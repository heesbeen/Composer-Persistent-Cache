<?php

declare(strict_types=1);

namespace HoarseLeg\PersistentCache;

use Composer\Package\PackageInterface;

final class Configuration
{
    /**
     * @param list<string> $packages
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly string $directory,
        private readonly array $packages
    ) {
    }

    public static function fromRootExtra(array $extra): self
    {
        if (!array_key_exists('persistent-cache', $extra)) {
            return new self(false, '.composer-cache', []);
        }

        $configuration = $extra['persistent-cache'];
        if (!is_array($configuration)) {
            throw new \InvalidArgumentException(
                'extra.persistent-cache must be an object with enabled, directory, and packages fields.'
            );
        }

        foreach (['enabled', 'directory', 'packages'] as $field) {
            if (!array_key_exists($field, $configuration)) {
                throw new \InvalidArgumentException(
                    sprintf('extra.persistent-cache.%s is required.', $field)
                );
            }
        }

        if (!is_bool($configuration['enabled'])) {
            throw new \InvalidArgumentException('extra.persistent-cache.enabled must be a boolean.');
        }

        $directory = $configuration['directory'];
        if (!is_string($directory) || !self::isSafeRelativeDirectory($directory)) {
            throw new \InvalidArgumentException(
                'extra.persistent-cache.directory must be a safe project-relative directory without "." or ".." segments.'
            );
        }

        $packages = $configuration['packages'];
        if (!is_array($packages) || !array_is_list($packages)) {
            throw new \InvalidArgumentException('extra.persistent-cache.packages must be a list of package names.');
        }

        $normalizedPackages = [];
        foreach ($packages as $package) {
            if (!is_string($package) || !self::isValidPackageName($package)) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid package name in extra.persistent-cache.packages: %s', json_encode($package))
                );
            }
            $normalizedPackages[] = strtolower($package);
        }

        return new self($configuration['enabled'], $directory, array_values(array_unique($normalizedPackages)));
    }

    public function allows(PackageInterface $package): bool
    {
        return $this->enabled && in_array(strtolower($package->getName()), $this->packages, true);
    }

    /** @return list<string> */
    public function getPackages(): array
    {
        return $this->packages;
    }

    private static function isSafeRelativeDirectory(string $directory): bool
    {
        if (
            $directory === ''
            || str_contains($directory, "\0")
            || str_contains($directory, '\\')
            || str_starts_with($directory, '/')
            || preg_match('/^[A-Za-z]:/', $directory) === 1
            || str_starts_with($directory, '~')
        ) {
            return false;
        }

        foreach (explode('/', $directory) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private static function isValidPackageName(string $name): bool
    {
        return preg_match(
            '/^[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?$/i',
            $name
        ) === 1;
    }
}
