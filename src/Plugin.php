<?php

namespace Tuf\ComposerIntegration;

use Composer\Composer;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Repository\RepositoryFactory;
use Composer\Repository\RepositoryManager;
use Composer\Util\ProcessExecutor;
use Tuf\ComposerIntegration\Repository\TufHttpAwareRepositoryManager;
use Tuf\ComposerIntegration\Util\TufValidatedHttpDownloader;


class Plugin implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
        // Credit for this pattern to zaporylie/composer-drupal-optimizations.
        // These three instantiations satisfy strict types on TufAwareHttpRepositoryManager::__construct only.
        // They are overwritten with the instances used by the rest of Composer inside the closure.
        $httpDownloader = Factory::createHttpDownloader($io, $composer->getConfig());
        $process = new ProcessExecutor($io);
        $dispatcher = new EventDispatcher($composer, $io, $process);

        $manager = new TufHttpAwareRepositoryManager($io, $composer->getConfig(), $httpDownloader, $dispatcher, $process);
        $manager->setTufValidatedHttpDownloader(new TufValidatedHttpDownloader($io, $composer->getConfig()));

        $fixupRepositoryManager = \Closure::bind(function (RepositoryManager $manager) {
            $manager->httpDownloader = $this->httpDownloader;
            $manager->eventDispatcher = $this->eventDispatcher;
            $manager->process = $this->process;

            $manager->repositoryClasses = $this->repositoryClasses;
            $manager->repositories = $this->repositories;
            $i = 0;
            foreach (RepositoryFactory::defaultRepos(null, $this->config, $manager) as $repo) {
                $manager->repositories[$i++] = $repo;
            }
            $manager->setLocalRepository($this->getLocalRepository());
        }, $composer->getRepositoryManager(), RepositoryManager::class);
        $fixupRepositoryManager($manager);

        $composer->setRepositoryManager($manager);
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
        // TODO: Implement uninstall() method.
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
        // TODO: Implement deactivate() method.
    }
}
