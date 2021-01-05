<?php


namespace Tuf\ComposerIntegration\Repository;


use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Repository\ComposerRepository;
use Composer\Repository\FilterRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Util\HttpDownloader;
use Composer\Util\ProcessExecutor;
use Tuf\ComposerIntegration\Util\TufValidatedHttpDownloader;

class TufHttpAwareRepositoryManager extends RepositoryManager
{
    protected $tufValidatedHttpDownloader;

    public function __construct(IOInterface $io, Config $config, HttpDownloader $httpDownloader, EventDispatcher $eventDispatcher = null, ProcessExecutor $process = null)
    {
        parent::__construct($io, $config, $httpDownloader, $eventDispatcher, $process);
    }

    public function setTufValidatedHttpDownloader(TufValidatedHttpDownloader $td) {
        $this->tufValidatedHttpDownloader = $td;
    }

    public function createRepository($type, $config, $name = null)
    {
        $repo = parent::createRepository($type, $config, $name);

        if ($type == 'composer') {
            // Cram the TUF-validated http Downloader into the private httpDownloader property.
            $tufValidatedHttpDownloader = $this->tufValidatedHttpDownloader;

            $setDownloader = \Closure::bind(function() use($tufValidatedHttpDownloader) {
                $this->httpDownloader = $tufValidatedHttpDownloader;
            }, $repo, ComposerRepository::class);

            $setDownloader();
        }

        return $repo;
    }
}
