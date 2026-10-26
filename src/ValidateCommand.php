<?php

declare(strict_types=1);

namespace HoarseLeg\PersistentCache;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\Package\PackageInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ValidateCommand extends BaseCommand
{
    public function __construct(private readonly Composer $composer)
    {
        parent::__construct('persistent-cache:validate');
    }

    protected function configure(): void
    {
        $this->setDescription('Validate project-local persistent package archives against composer.lock.');
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
            $output->writeln('<comment>Persistent package caching is disabled or not configured.</comment>');

            return self::SUCCESS;
        }

        if (!$this->composer->getLocker()->isLocked()) {
            $output->writeln('<error>No composer.lock file is available to validate against.</error>');

            return self::FAILURE;
        }

        $projectRoot = getcwd();
        if ($projectRoot === false) {
            $output->writeln('<error>Could not determine the Composer project directory.</error>');

            return self::FAILURE;
        }

        $cache = new PackageCache($configuration, $projectRoot);
        $lockedPackages = $this->composer->getLocker()->getLockedRepository(true)->getPackages();
        $failure = false;
        $valid = 0;
        $missing = 0;
        $invalid = 0;
        $unsupported = 0;

        foreach ($lockedPackages as $package) {
            if (!$configuration->allows($package)) {
                continue;
            }

            $result = $cache->validatePackage($package);
            if ($result['status'] === 'valid') {
                $valid++;
            } elseif ($result['status'] === 'missing') {
                $missing++;
                $failure = true;
                $output->writeln(sprintf('<comment>Missing</comment> %s %s: %s', $package->getName(), $package->getPrettyVersion(), $result['message']));
            } elseif ($result['status'] === 'unsupported') {
                $unsupported++;
                $output->writeln(sprintf('<comment>Unsupported</comment> %s %s: %s', $package->getName(), $package->getPrettyVersion(), $result['message']));
            } else {
                $invalid++;
                $failure = true;
                $output->writeln(sprintf('<error>Invalid</error> %s %s: %s', $package->getName(), $package->getPrettyVersion(), $result['message']));
            }
        }

        $stale = $cache->findStaleEntries($lockedPackages);
        foreach ($stale as $entry) {
            $failure = true;
            $output->writeln(sprintf('<comment>Stale</comment> %s: no matching allowlisted locked package.', $entry));
        }

        $output->writeln(sprintf(
            'Cache validation: %d valid, %d missing, %d invalid, %d unsupported, %d stale.',
            $valid,
            $missing,
            $invalid,
            $unsupported,
            count($stale)
        ));

        return $failure ? self::FAILURE : self::SUCCESS;
    }
}
