<?php


namespace Tuf\ComposerIntegration\Repository;


use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositorySecurityException;
use Composer\Util\Filesystem;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use React\Promise\Promise;
use Tuf\Client\DurableStorage\FileStorage;
use Tuf\Client\Updater;
use Tuf\Exception\TufException;

class TufValidatedComposerRepository extends ComposerRepository
{
    /**
     * @var bool
     *   Indicates whether this Composer repository is validated by a parallel
     *   TUF repository.
     */
    protected $isValidated;

    /**
     * @var bool
     *   Indicates whether a refresh() has been performed on our TUF metadata.
     */
    protected $isRefreshed = false;

    /**
     * @var array
     *   HTTP request options.
     */
    protected $options;

    /**
     * @var bool
     *   Indicates whether the repository is expected to have a functioning http fallback.
     *
     *   Unlike ComposerRepository, this defaults to true if not set by the root composer.json.
     *   HTTPS is not the primary means of security in TUF-validated repositories.
     */
    protected $allowSslDowngrade;

    /**
     * @var string
     *   The URL to the composer repository root.
     *
     *   Serves same purpose as parent::$url, but its visibility is private.
     */
    protected $composerRepoUrl;

    /**
     * @var Updater
     */
    protected $tufRepo;

    /**
     * @var EventDispatcher
     */
    protected $eventDispatcher;

    /**
     * @var HttpDownloader
     */
    protected $httpDownloader;

    /**
     * @var bool
     */
    protected $degradedMode = false;

    /**
     * @var IOInterface
     */
    protected $io;

    public function __construct(array $repoConfig, IOInterface $io, Config $config, HttpDownloader $httpDownloader, EventDispatcher $eventDispatcher = null)
    {
        parent::__construct($repoConfig, $io, $config, $httpDownloader, $eventDispatcher);

        $this->isValidated = false;
        $this->composerRepoUrl = $repoConfig['url'];
        if (!empty($repoConfig['tuf'])) {
            $this->isValidated = true;
            $this->eventDispatcher = $eventDispatcher;
            $this->httpDownloader = $httpDownloader;
            $this->io = $io;
            if (!isset($repoConfig['options'])) {
                $repoConfig['options'] = array();
            }
            $this->options = $repoConfig['options'];
            $this->allowSslDowngrade = true;
            if (array_key_exists('allow_ssl_downgrade', $repoConfig)) {
                $this->allowSslDowngrade = $repoConfig['allow_ssl_downgrade'];
            }

            $tufConfig = $repoConfig['tuf'];

            // @todo: Write a custom implementation of FileStorage that stores repo keys to user's global composer cache?
            $allowList = 'a-z0-9.';
            $repoPath = preg_replace('{[^'.$allowList.']}i', '-', $this->composerRepoUrl);
            // Harvest the vendor dir from Composer. We'll store TUF state under vendor/composer/tuf.
            $vendorDir = rtrim($config->get('vendor-dir'), '/');
            $repoPath = "$vendorDir/composer/tuf/repo/$repoPath";
            // Ensure directory exists.
            $fs = new Filesystem();
            $fs->ensureDirectoryExists($repoPath);
            $tufDurableStorage = new FileStorage($repoPath);
            // Instantiate TUF library.
            $this->tufRepo = new Updater($this->composerRepoUrl, [
              ['url_prefix' => $tufConfig['url']]
            ], $tufDurableStorage);
        } else {
            // Outputting composer repositories not secured by TUF may create confusion about other
            // not-secured repository types (eg, "vcs").
            // @todo Usability assessment. Should we output this for other repo types, or not at all?
            $io->warning("Authenticity of packages from ${repoConfig['url']} are not verified by TUF.");
        }
    }

    protected function loadRootServerFile()
    {
        if ($this->isValidated && !$this->isRefreshed) {
            try {
                $this->tufRepo->refresh();
                $this->isRefreshed = true;
            } catch (TufException $e) {
                throw new RepositorySecurityException('TUF secure error: ' . $e->getMessage(), $e->getCode(), $e);
            }
        }

        return parent::loadRootServerFile();
    }

    /**
     * Reimplementation of parent::fetchFile that obtains files as validated TUF targets.
     *
     * @param string $filename
     * @param string $cacheKey
     * @param string $sha256
     * @param bool $storeLastModifiedTime
     * @return array
     * @throws RepositorySecurityException
     */
    protected function fetchFile($filename, $cacheKey = null, $sha256 = null, $storeLastModifiedTime = false)
    {
        if (!$this->isValidated) {
            return parent::fetchFile($filename, $cacheKey, $sha256, $storeLastModifiedTime);
        }

        // url-encode $ signs in URLs as bad proxies choke on them
        if (($pos = strpos($filename, '$')) && preg_match('{^https?://}i', $filename)) {
            $filename = substr($filename, 0, $pos) . '%24' . substr($filename, $pos + 1);
        }

        $retries = 3;
        while ($retries--) {
            try {
                if ($this->eventDispatcher) {
                    $preFileDownloadEvent = new PreFileDownloadEvent(PluginEvents::PRE_FILE_DOWNLOAD, $this->httpDownloader, $filename, 'metadata');
                    $this->eventDispatcher->dispatch($preFileDownloadEvent->getName(), $preFileDownloadEvent);
                    $filename = $preFileDownloadEvent->getProcessedUrl();
                }

                $tufTarget = ltrim(parse_url($filename, PHP_URL_PATH), '/');
                $tufTargetInfo = $this->tufRepo->getOneValidTargetInfo($tufTarget);
                // @todo: Investigate whether all $sha256 hashes, when provided, are trusted. Skip TUF if so.
                if ($sha256 != null && $sha256 != $tufTargetInfo['hashes']['sha256']) {
                    throw new RepositorySecurityException('TUF secure error: disagreement between TUF and Composer repositories on expected hash of ' . $tufTarget);
                }
                $sha256 = $tufTargetInfo['hashes']['sha256'];
                // @todo: Add expected length to the get options, implement download abort by length.
                // See CURLOPT_PROGRESSFUNCTION/CURLOPT_WRITEFUNCTION.
                $response = $this->httpDownloader->get($filename, $this->options);
                $json = $response->getBody();
                if ($sha256 !== hash('sha256', $json)) {
                    if ($retries) {
                        usleep(100000);

                        continue;
                    }

                    // TODO use scarier wording once we know for sure it doesn't do false positives anymore
                    throw new RepositorySecurityException('The contents of '.$filename.' do not match its signature. This could indicate a man-in-the-middle attack or e.g. antivirus software corrupting files. Try running composer again and report this if you think it is a mistake.');
                }

                $data = $response->decodeJson();
                HttpDownloader::outputWarnings($this->io, $this->composerRepoUrl, $data);

                if ($cacheKey && !$this->cache->isReadOnly()) {
                    if ($storeLastModifiedTime) {
                        $lastModifiedDate = $response->getHeader('last-modified');
                        if ($lastModifiedDate) {
                            $data['last-modified'] = $lastModifiedDate;
                            $json = json_encode($data);
                        }
                    }
                    $this->cache->write($cacheKey, $json);
                }

                $response->collect();

                break;
            } catch (\Exception $e) {
                if ($e instanceof \LogicException) {
                    throw $e;
                }

                if ($e instanceof TransportException && $e->getStatusCode() === 404) {
                    throw $e;
                }

                if ($retries) {
                    // Try http if we were trying https.
                    $this->downgradeToHttp();
                    if ($this->allowSslDowngrade) {
                        $filename = str_replace('https://', 'http://', $filename);
                    }

                    usleep(100000);
                    continue;
                }

                if ($e instanceof TufException) {
                    $e = new RepositorySecurityException('TUF secure error: ' . $e->getMessage(), $e->getCode(), $e);
                }

                if ($e instanceof RepositorySecurityException) {
                    throw $e;
                }

                if ($cacheKey && ($contents = $this->cache->read($cacheKey))) {
                    if (!$this->degradedMode) {
                        $this->io->writeError('<warning>' . $this->composerRepoUrl . ' could not be fully loaded (' . $e->getMessage() . '), package information was loaded from the local cache and may be out of date</warning>');
                    }
                    $this->degradedMode = true;
                    $data = JsonFile::parseJson($contents, $this->cache->getRoot() . $cacheKey);

                    break;
                }

                throw $e;
            }
        }
        if (!isset($data)) {
            throw new \LogicException("TufValidatedComposerRepository: Undefined \$data. Please report at https://github.com/php-tuf/composer-integration/issues/new.");
        }

        return $data;
    }

    protected function asyncFetchFile($filename, $cacheKey, $lastModifiedTime = null)
    {
        if (! $this->isValidated) {
            return parent::asyncFetchFile($filename, $cacheKey, $lastModifiedTime);
        }

        $retries = 3;

        if (isset($this->packagesNotFoundCache[$filename])) {
            return \React\Promise\resolve(array('packages' => array()));
        }

        if (isset($this->freshMetadataUrls[$filename]) && $lastModifiedTime) {
            // make it look like we got a 304 response
            return \React\Promise\resolve(true);
        }

        $httpDownloader = $this->httpDownloader;
        if ($this->eventDispatcher) {
            $preFileDownloadEvent = new PreFileDownloadEvent(PluginEvents::PRE_FILE_DOWNLOAD, $this->httpDownloader, $filename, 'metadata');
            $this->eventDispatcher->dispatch($preFileDownloadEvent->getName(), $preFileDownloadEvent);
            $filename = $preFileDownloadEvent->getProcessedUrl();
        }

        $options = $this->options;
        if ($lastModifiedTime) {
            if (isset($options['http']['header'])) {
                $options['http']['header'] = (array) $options['http']['header'];
            }
            $options['http']['header'][] = 'If-Modified-Since: '.$lastModifiedTime;
        }

        $io = $this->io;
        $url = $this->composerRepoUrl;
        $cache = $this->cache;
        $degradedMode =& $this->degradedMode;
        $repo = $this;

        $accept = function ($response) use ($io, $url, $filename, $cache, $cacheKey, $repo) {
            // package not found is acceptable for a v2 protocol repository
            if ($response->getStatusCode() === 404) {
                $repo->packagesNotFoundCache[$filename] = true;
                return array('packages' => array());
            }

            $json = $response->getBody();
            if ($json === '' && $response->getStatusCode() === 304) {
                $repo->freshMetadataUrls[$filename] = true;
                return true;
            }

            $data = $response->decodeJson();
            HttpDownloader::outputWarnings($io, $url, $data);

            $lastModifiedDate = $response->getHeader('last-modified');
            $response->collect();
            if ($lastModifiedDate) {
                $data['last-modified'] = $lastModifiedDate;
                $json = JsonFile::encode($data, JsonFile::JSON_UNESCAPED_SLASHES | JsonFile::JSON_UNESCAPED_UNICODE);
            }
            if (!$cache->isReadOnly()) {
                $cache->write($cacheKey, $json);
            }
            $repo->freshMetadataUrls[$filename] = true;

            return $data;
        };

        $reject = function ($e) use (&$retries, $httpDownloader, $filename, $options, &$reject, $accept, $io, $url, &$degradedMode, $repo) {
            if ($e instanceof TransportException && $e->getStatusCode() === 404) {
                $repo->packagesNotFoundCache[$filename] = true;
                return false;
            }

            // special error code returned when network is being artificially disabled
            if ($e instanceof TransportException && $e->getStatusCode() === 499) {
                $retries = 0;
            }

            if (--$retries > 0) {
                usleep(100000);

                return $httpDownloader->add($filename, $options)->then($accept, $reject);
            }

            if (!$degradedMode) {
                $io->writeError('<warning>'.$url.' could not be fully loaded ('.$e->getMessage().'), package information was loaded from the local cache and may be out of date</warning>');
            }
            $degradedMode = true;

            // special error code returned when network is being artificially disabled
            if ($e instanceof TransportException && $e->getStatusCode() === 499) {
                return $accept(new Response(array('url' => $url), 404, array(), ''));
            }

            throw $e;
        };

        $tufAccept = function($targetInfo) use($httpDownloader, $filename, $options) {
            return $httpDownloader->add($filename, $options);
        };

        $tufReject = function($e) {
            throw $e;
        };

        return $this->addInitialPromise($filename)->then($tufAccept, $tufReject)->then($accept, $reject);
    }

    protected function addInitialPromise($filename) {
        $io = $this->io;
        $bsResolver = function ($resolve, $reject) use($io, $filename) {
            $io->info("Waiting 1 second before starting async access to $filename");
            sleep(1);
            $resolve(true);
        };

        $initialPromise = new Promise($bsResolver, null);
        return $initialPromise;
    }

    /**
     * Reimplementation of parent::canonicalizeUrl(), whose visibility is private.
     */
    protected function canonicalizeUrl($url)
    {
        if ('/' === $url[0]) {
            if (preg_match('{^[^:]++://[^/]*+}', $this->composerRepoUrl, $matches)) {
                return $matches[0] . $url;
            }

            return $this->composerRepoUrl;
        }

        return $url;
    }

    protected function downgradeToHttp()
    {
        if ($this->allowSslDowngrade) {
            $this->composerRepoUrl = str_replace("https://", "http://", $this->composerRepoUrl);
        }
        // @todo (php-tuf): Implement method for application to request that TUF switch to http.
    }
}