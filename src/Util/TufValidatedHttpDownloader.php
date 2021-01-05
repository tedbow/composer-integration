<?php


namespace Tuf\ComposerIntegration\Util;

use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use React\Promise\Promise;

class TufValidatedHttpDownloader extends HttpDownloader
{
    public function __construct(IOInterface $io, Config $config, array $options = array(), $disableTls = false)
    {
        parent::__construct($io, $config, $options, $disableTls);

        // Cram the Tuf validated cURL wrapper into this downloader's curl property.

    }
}
