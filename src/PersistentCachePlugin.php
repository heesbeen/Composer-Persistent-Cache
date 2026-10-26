<?php

declare(strict_types=1);

namespace HoarseLeg\PersistentCache;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Plugin\PostFileDownloadEvent;

final class PersistentCachePlugin implements PluginInterface, EventSubscriberInterface, Capable
{
    private ?PackageCache $cache = null;
    private ?Configuration $configuration = null;
    private ?IOInterface $io = null;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->io = $io;
        try {
            $this->configuration = Configuration::fromRootExtra($composer->getPackage()->getExtra());
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException('Invalid persistent-cache configuration: ' . $exception->getMessage(), 0, $exception);
        }

        $projectRoot = getcwd();
        if ($projectRoot === false) {
            throw new \RuntimeException('Could not determine the Composer project directory.');
        }
        $this->cache = new PackageCache($this->configuration, $projectRoot);
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
        $this->cache = null;
        $this->configuration = null;
        $this->io = null;
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::PRE_FILE_DOWNLOAD => ['onPreFileDownload', 100],
            PluginEvents::POST_FILE_DOWNLOAD => 'onPostFileDownload',
        ];
    }

    public function onPreFileDownload(PreFileDownloadEvent $event): void
    {
        $package = $event->getContext();
        if (!$package instanceof PackageInterface || $event->getType() !== 'package' || $this->cache === null) {
            return;
        }

        $archive = $this->cache->findCachedArchive($package);
        if ($archive !== null) {
            $event->setProcessedUrl(self::fileUrl($archive));
        }
    }

    public function onPostFileDownload(PostFileDownloadEvent $event): void
    {
        $package = $event->getContext();
        $archive = $event->getFileName();
        if (
            !$package instanceof PackageInterface
            || $event->getType() !== 'package'
            || $archive === null
            || $this->cache === null
            || !$this->configuration?->allows($package)
            || strtolower((string) $package->getDistType()) !== 'zip'
            || $this->cache->isCachedArchiveUrl($package, $event->getUrl())
        ) {
            return;
        }

        if (!$this->cache->storeArchive($package, $archive)) {
            $this->io?->writeError(
                sprintf('Skipping persistent cache population for %s: archive could not be safely validated or stored.', $package->getName()),
                true,
                IOInterface::DEBUG
            );
        }
    }

    public function getCapabilities(): array
    {
        return [
            'Composer\\Plugin\\Capability\\CommandProvider' => CommandProvider::class,
        ];
    }

    private static function fileUrl(string $path): string
    {
        $normalizedPath = str_replace('\\', '/', $path);
        if (preg_match('/^[A-Za-z]:\//', $normalizedPath) === 1) {
            $drive = substr($normalizedPath, 0, 2);
            $segments = explode('/', substr($normalizedPath, 3));

            return 'file:///' . $drive . '/' . implode('/', array_map('rawurlencode', $segments));
        }

        return 'file://' . implode('/', array_map('rawurlencode', explode('/', $normalizedPath)));
    }
}
