<?php

declare(strict_types=1);

namespace HoarseLeg\PersistentCache;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\Package\PackageInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PackCommand extends BaseCommand
{
    public function __construct(private readonly Composer $composer)
    {
        parent::__construct('persistent-cache:pack');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Pack an allowlisted, locked package from vendor/ into the persistent cache.')
            ->addArgument('package', InputArgument::REQUIRED, 'Package name as listed in persistent-cache.packages.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $configuration = Configuration::fromRootExtra($this->composer->getPackage()->getExtra());
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>Invalid persistent-cache configuration: ' . $exception->getMessage() . '</error>');

            return self::FAILURE;
        }

        if (!$configuration->enabled) {
            $output->writeln('<error>Persistent package caching is disabled or not configured.</error>');

            return self::FAILURE;
        }

        $packageName = strtolower((string) $input->getArgument('package'));
        $lockedPackages = $this->composer->getLocker()->getLockedRepository(true)->getPackages();
        $lockedPackage = $this->findPackage($lockedPackages, $packageName);
        if ($lockedPackage === null) {
            $output->writeln(sprintf('<error>Package "%s" is not present in composer.lock.</error>', $packageName));

            return self::FAILURE;
        }
        if (!$configuration->allows($lockedPackage)) {
            $output->writeln(sprintf('<error>Package "%s" is not enabled in the persistent-cache allowlist.</error>', $packageName));

            return self::FAILURE;
        }
        if (strtolower((string) $lockedPackage->getDistType()) !== 'zip') {
            $output->writeln(sprintf('<error>Package "%s" does not have a locked ZIP distribution and cannot be packed.</error>', $packageName));

            return self::FAILURE;
        }
        if ($lockedPackage->getDistSha1Checksum() !== null && $lockedPackage->getDistSha1Checksum() !== '') {
            $output->writeln(sprintf(
                '<error>Package "%s" has a locked distribution SHA-1. Composer requires that exact original archive checksum, so repacking its vendor files would make installation fail.</error>',
                $packageName
            ));

            return self::FAILURE;
        }

        $installedPackage = $this->findPackage(
            $this->composer->getRepositoryManager()->getLocalRepository()->getPackages(),
            $packageName
        );
        if ($installedPackage === null || $installedPackage->getVersion() !== $lockedPackage->getVersion()) {
            $output->writeln(sprintf(
                '<error>Package "%s" must be installed at the exact locked version before it can be packed.</error>',
                $packageName
            ));

            return self::FAILURE;
        }

        $lockedReference = $lockedPackage->getDistReference();
        $installedReference = $installedPackage->getDistReference() ?? $installedPackage->getSourceReference();
        if ($lockedReference !== null && $installedReference !== null && $lockedReference !== $installedReference) {
            $output->writeln(sprintf(
                '<error>Installed package "%s" has a different distribution reference from composer.lock.</error>',
                $packageName
            ));

            return self::FAILURE;
        }

        $projectRoot = getcwd();
        if ($projectRoot === false) {
            $output->writeln('<error>Could not determine the Composer project directory.</error>');

            return self::FAILURE;
        }

        $installPath = $this->composer->getInstallationManager()->getInstallPath($installedPackage);
        $vendorDirectory = $this->composer->getConfig()->get('vendor-dir');
        if (!is_string($vendorDirectory)) {
            $output->writeln('<error>Composer did not provide a usable vendor directory.</error>');

            return self::FAILURE;
        }
        if (!self::isAbsolutePath($vendorDirectory)) {
            $vendorDirectory = $projectRoot . DIRECTORY_SEPARATOR . $vendorDirectory;
        }

        $realVendorDirectory = realpath($vendorDirectory);
        $realInstallPath = $installPath !== null ? realpath($installPath) : false;
        if (
            $realVendorDirectory === false
            || $realInstallPath === false
            || !is_dir($realInstallPath)
            || !self::isWithinDirectory($realInstallPath, $realVendorDirectory)
        ) {
            $output->writeln(sprintf('<error>Could not safely resolve "%s" inside the Composer vendor directory.</error>', $packageName));

            return self::FAILURE;
        }

        $temporaryArchive = tempnam(sys_get_temp_dir(), 'composer-persistent-cache-');
        if ($temporaryArchive === false) {
            $output->writeln('<error>Could not create a temporary ZIP archive.</error>');

            return self::FAILURE;
        }

        try {
            if (!$this->createArchive($realInstallPath, $temporaryArchive)) {
                $output->writeln('<error>The installed package contains an unsafe or unreadable filesystem entry; no cache archive was written.</error>');

                return self::FAILURE;
            }

            $cache = new PackageCache($configuration, $projectRoot);
            if (!$cache->storeArchive($lockedPackage, $temporaryArchive, 'vendor')) {
                $output->writeln('<error>The packed archive could not be safely validated or stored.</error>');

                return self::FAILURE;
            }
        } finally {
            if (file_exists($temporaryArchive)) {
                unlink($temporaryArchive);
            }
        }

        $output->writeln(sprintf(
            '<info>Packed %s %s from vendor/ into the persistent cache.</info>',
            $lockedPackage->getName(),
            $lockedPackage->getPrettyVersion()
        ));
        return self::SUCCESS;
    }

    /**
     * @param list<PackageInterface> $packages
     */
    private function findPackage(array $packages, string $name): ?PackageInterface
    {
        $matches = array_values(array_filter(
            $packages,
            static fn (PackageInterface $package): bool => strtolower($package->getName()) === $name
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function createArchive(string $packageDirectory, string $archivePath): bool
    {
        $archive = new \ZipArchive();
        if ($archive->open($archivePath, \ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $success = true;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($packageDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isLink()) {
                $success = false;
                break;
            }

            $relativePath = str_replace('\\', '/', substr($path, strlen($packageDirectory) + 1));
            if ($relativePath === '' || str_starts_with($relativePath, '/') || in_array('..', explode('/', $relativePath), true)) {
                $success = false;
                break;
            }

            $permissions = fileperms($path);
            if ($permissions === false) {
                $success = false;
                break;
            }
            $mode = $permissions & 0777;

            if ($entry->isDir()) {
                $success = $archive->addEmptyDir($relativePath);
                if ($success) {
                    $success = $archive->setExternalAttributesName(
                        $relativePath . '/',
                        \ZipArchive::OPSYS_UNIX,
                        (0040000 | $mode) << 16
                    );
                }
            } elseif ($entry->isFile()) {
                $success = $archive->addFile($path, $relativePath);
                if ($success) {
                    $success = $archive->setExternalAttributesName(
                        $relativePath,
                        \ZipArchive::OPSYS_UNIX,
                        (0100000 | $mode) << 16
                    );
                }
            } else {
                $success = false;
            }

            if (!$success) {
                break;
            }
        }

        $closed = $archive->close();

        return $success && $closed;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private static function isWithinDirectory(string $path, string $directory): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);

        return $path !== $directory && str_starts_with($path, $directory . DIRECTORY_SEPARATOR);
    }
}
