<?php

declare(strict_types=1);

namespace HoarseLeg\PersistentCache;

use Composer\Package\PackageInterface;

final class PackageCache
{
    private string $projectRoot;
    private string $cacheRoot;

    public function __construct(
        private readonly Configuration $configuration,
        string $projectRoot,
        private readonly ArchiveValidator $validator = new ArchiveValidator()
    ) {
        $realProjectRoot = realpath($projectRoot);
        if ($realProjectRoot === false || !is_dir($realProjectRoot)) {
            throw new \InvalidArgumentException('The Composer project directory could not be resolved.');
        }

        $this->projectRoot = $realProjectRoot;
        $this->cacheRoot = $realProjectRoot . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $configuration->directory);
    }

    public function findCachedArchive(PackageInterface $package): ?string
    {
        if (!$this->isSupportedPackage($package)) {
            return null;
        }

        $paths = $this->pathsFor($package);
        if (!$this->existingDirectoryChainIsSafe(dirname($paths['archive']))) {
            return null;
        }

        $validation = $this->validateAtPaths($package, $paths);

        return $validation['status'] === 'valid' ? $paths['archive'] : null;
    }

    /**
     * Copy a Composer-verified distribution archive into the project cache.
     */
    public function storeArchive(PackageInterface $package, string $source, string $archiveSource = 'distribution'): bool
    {
        if (
            !$this->isSupportedPackage($package)
            || !in_array($archiveSource, ['distribution', 'vendor'], true)
            || !$this->validator->isValidZip($source)
        ) {
            return false;
        }

        $expectedSha1 = $package->getDistSha1Checksum();
        if (
            $expectedSha1 !== null
            && $expectedSha1 !== ''
            && (
                $archiveSource === 'vendor'
                || !hash_equals($expectedSha1, (string) hash_file('sha1', $source))
            )
        ) {
            return false;
        }

        $paths = $this->pathsFor($package);
        if (!$this->ensureDirectoryChain(dirname($paths['archive']))) {
            return false;
        }

        $lockPath = $this->cacheRoot . DIRECTORY_SEPARATOR . '.write.lock';
        if (!$this->ensureDirectoryChain($this->cacheRoot) || is_link($lockPath)) {
            return false;
        }
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) {
            return false;
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                return false;
            }

            if ($this->validateAtPaths($package, $paths)['status'] === 'valid') {
                return true;
            }

            $temporaryArchive = tempnam(dirname($paths['archive']), '.archive-');
            if ($temporaryArchive === false) {
                return false;
            }

            try {
                if (!copy($source, $temporaryArchive) || !$this->validator->isValidZip($temporaryArchive)) {
                    return false;
                }

                $archiveSize = filesize($temporaryArchive);
                $checksum = hash_file('sha256', $temporaryArchive);
                if ($archiveSize === false || $checksum === false) {
                    return false;
                }

                $metadata = [
                    'schemaVersion' => 2,
                    'packageName' => strtolower($package->getName()),
                    'packageVersion' => $package->getVersion(),
                    'distReference' => $package->getDistReference(),
                    'distType' => 'zip',
                    'archiveFilename' => 'package.zip',
                    'archiveSource' => $archiveSource,
                    'archiveSha256' => $checksum,
                    'archiveSize' => $archiveSize,
                    'createdAt' => gmdate('c'),
                ];

                $temporaryMetadata = tempnam(dirname($paths['metadata']), '.metadata-');
                if ($temporaryMetadata === false) {
                    return false;
                }

                try {
                    $encodedMetadata = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                    if (file_put_contents($temporaryMetadata, $encodedMetadata, LOCK_EX) !== strlen($encodedMetadata)) {
                        return false;
                    }
                    if (!rename($temporaryArchive, $paths['archive'])) {
                        return false;
                    }
                    if (!rename($temporaryMetadata, $paths['metadata'])) {
                        return false;
                    }

                    return true;
                } finally {
                    if (file_exists($temporaryMetadata)) {
                        unlink($temporaryMetadata);
                    }
                }
            } finally {
                if (file_exists($temporaryArchive)) {
                    unlink($temporaryArchive);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array{status: string, message: string}
     */
    public function validatePackage(PackageInterface $package): array
    {
        if (!$this->isSupportedPackage($package)) {
            return ['status' => 'unsupported', 'message' => 'Package is not allowlisted with a ZIP distribution.'];
        }

        $paths = $this->pathsFor($package);
        if (!$this->existingDirectoryChainIsSafe(dirname($paths['archive']))) {
            return ['status' => 'missing', 'message' => 'Cache entry is missing or contains a symbolic link.'];
        }

        return $this->validateAtPaths($package, $paths);
    }

    /**
     * @param list<PackageInterface> $lockedPackages
     * @return list<string>
     */
    public function findStaleEntries(array $lockedPackages): array
    {
        $expectedEntryDirectories = [];
        foreach ($lockedPackages as $package) {
            if ($this->isSupportedPackage($package)) {
                $expectedEntryDirectories[dirname($this->pathsFor($package)['archive'])] = true;
            }
        }

        $packagesDirectory = $this->cacheRoot . DIRECTORY_SEPARATOR . 'packages';
        if (!$this->existingDirectoryChainIsSafe($packagesDirectory)) {
            return [];
        }

        $stale = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($packagesDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            $relativePath = substr($file->getPathname(), strlen($packagesDirectory) + 1);
            if (
                count(explode(DIRECTORY_SEPARATOR, $relativePath)) !== 3
                || (!$file->isDir() && !$file->isLink())
            ) {
                continue;
            }
            $path = $file->getPathname();
            if (!isset($expectedEntryDirectories[$path])) {
                $stale[] = $path;
            }
        }

        return $stale;
    }

    public function isCachedArchiveUrl(PackageInterface $package, string $url): bool
    {
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'file') {
            return false;
        }

        $urlPath = parse_url($url, PHP_URL_PATH);
        if (!is_string($urlPath)) {
            return false;
        }

        $decodedPath = str_replace('\\', '/', rawurldecode($urlPath));
        $expectedPath = str_replace('\\', '/', $this->pathsFor($package)['archive']);
        if (preg_match('/^\/[A-Za-z]:\//', $decodedPath) === 1) {
            $decodedPath = substr($decodedPath, 1);
        }

        return $decodedPath === $expectedPath;
    }

    /**
     * @param array{archive: string, metadata: string} $paths
     * @return array{status: string, message: string}
     */
    private function validateAtPaths(PackageInterface $package, array $paths): array
    {
        if (!is_file($paths['metadata']) || is_link($paths['metadata'])) {
            return ['status' => 'missing', 'message' => 'Metadata file is missing.'];
        }
        if (!is_file($paths['archive']) || is_link($paths['archive'])) {
            return ['status' => 'missing', 'message' => 'Archive file is missing.'];
        }

        $contents = file_get_contents($paths['metadata']);
        if ($contents === false) {
            return ['status' => 'invalid', 'message' => 'Metadata file cannot be read.'];
        }

        try {
            $metadata = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['status' => 'invalid', 'message' => 'Metadata is not valid JSON.'];
        }

        if (!is_array($metadata)) {
            return ['status' => 'invalid', 'message' => 'Metadata must contain a JSON object.'];
        }

        $expected = [
            'schemaVersion' => 2,
            'packageName' => strtolower($package->getName()),
            'packageVersion' => $package->getVersion(),
            'distReference' => $package->getDistReference(),
            'distType' => 'zip',
            'archiveFilename' => 'package.zip',
        ];
        foreach ($expected as $field => $value) {
            if (!array_key_exists($field, $metadata) || $metadata[$field] !== $value) {
                return ['status' => 'invalid', 'message' => sprintf('Metadata mismatch for %s.', $field)];
            }
        }

        if (
            !isset($metadata['archiveSource'])
            || !in_array($metadata['archiveSource'], ['distribution', 'vendor'], true)
            || !isset($metadata['archiveSha256'])
            || !is_string($metadata['archiveSha256'])
            || preg_match('/^[a-f0-9]{64}$/', $metadata['archiveSha256']) !== 1
            || !isset($metadata['archiveSize'])
            || !is_int($metadata['archiveSize'])
            || $metadata['archiveSize'] < 1
            || !isset($metadata['createdAt'])
            || !is_string($metadata['createdAt'])
            || !$this->isValidTimestamp($metadata['createdAt'])
        ) {
            return ['status' => 'invalid', 'message' => 'Metadata integrity fields are invalid.'];
        }

        $actualSize = filesize($paths['archive']);
        if ($actualSize === false || $actualSize !== $metadata['archiveSize']) {
            return ['status' => 'invalid', 'message' => 'Archive size does not match metadata.'];
        }

        $actualSha256 = hash_file('sha256', $paths['archive']);
        if ($actualSha256 === false || !hash_equals($metadata['archiveSha256'], $actualSha256)) {
            return ['status' => 'invalid', 'message' => 'Archive SHA-256 checksum does not match metadata.'];
        }

        $expectedSha1 = $package->getDistSha1Checksum();
        if (
            $expectedSha1 !== null
            && $expectedSha1 !== ''
            && (
                $metadata['archiveSource'] === 'vendor'
                || !hash_equals($expectedSha1, (string) hash_file('sha1', $paths['archive']))
            )
        ) {
            return [
                'status' => 'invalid',
                'message' => $metadata['archiveSource'] === 'vendor'
                    ? 'Composer requires the original distribution SHA-1, so a vendor-repacked archive cannot be used for this package.'
                    : 'Archive SHA-1 checksum does not match the locked package checksum.',
            ];
        }

        if (!$this->validator->isValidZip($paths['archive'])) {
            return ['status' => 'invalid', 'message' => 'Archive is not a complete, valid ZIP file.'];
        }

        return ['status' => 'valid', 'message' => 'Cache entry is valid.'];
    }

    private function isValidTimestamp(string $timestamp): bool
    {
        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $timestamp);
        $errors = \DateTimeImmutable::getLastErrors();

        return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }

    private function isSupportedPackage(PackageInterface $package): bool
    {
        return $this->configuration->allows($package)
            && strtolower((string) $package->getDistType()) === 'zip'
            && $package->getVersion() !== '';
    }

    /**
     * @return array{archive: string, metadata: string}
     */
    private function pathsFor(PackageInterface $package): array
    {
        $segments = explode('/', strtolower($package->getName()));
        $entryDirectory = $this->cacheRoot . DIRECTORY_SEPARATOR . 'packages'
            . DIRECTORY_SEPARATOR . $segments[0] . DIRECTORY_SEPARATOR . $segments[1]
            . DIRECTORY_SEPARATOR . CacheKey::forPackage($package);

        return [
            'archive' => $entryDirectory . DIRECTORY_SEPARATOR . 'package.zip',
            'metadata' => $entryDirectory . DIRECTORY_SEPARATOR . 'metadata.json',
        ];
    }

    private function existingDirectoryChainIsSafe(string $directory): bool
    {
        $relative = ltrim(substr($directory, strlen($this->projectRoot)), DIRECTORY_SEPARATOR);
        $current = $this->projectRoot;
        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
            if ($segment === '') {
                continue;
            }
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (!file_exists($current) && !is_link($current)) {
                return false;
            }
            $stat = @lstat($current);
            if ($stat === false || is_link($current) || !is_dir($current)) {
                return false;
            }
        }

        return true;
    }

    private function ensureDirectoryChain(string $directory): bool
    {
        $relative = ltrim(substr($directory, strlen($this->projectRoot)), DIRECTORY_SEPARATOR);
        $current = $this->projectRoot;
        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
            if ($segment === '') {
                continue;
            }
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current)) {
                return false;
            }
            if (is_dir($current)) {
                continue;
            }
            if (file_exists($current) || !@mkdir($current, 0777) && !is_dir($current)) {
                return false;
            }
            if (is_link($current) || !is_dir($current)) {
                return false;
            }
        }

        return str_starts_with($current, $this->projectRoot);
    }
}
