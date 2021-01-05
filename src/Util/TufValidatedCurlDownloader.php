<?php


namespace Tuf\ComposerIntegration\Util;


use Composer\Util\Http\CurlDownloader;

class TufValidatedCurlDownloader extends CurlDownloader
{
    public function download($resolve, $reject, $origin, $url, $options, $copyTo = null)
    {
        return parent::download($resolve, $reject, $origin, $url, $options, $copyTo);
    }
}