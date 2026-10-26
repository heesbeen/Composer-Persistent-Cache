<?php

declare(strict_types=1);

namespace HoarseLeg\PersistentCache\Tests\Unit;

use HoarseLeg\PersistentCache\Configuration;
use HoarseLeg\PersistentCache\PackageCache;
use Composer\Package\PackageInterface;
use PHPUnit\Framework\TestCase;

final class PackageCacheTest extends TestCase
{
    private string $projectRoot;
    private string $sourceArchive;
    private PackageCache $cache;
    private PackageInterface $package;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/persistent-cache-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot);
        $this->sourceArchive = $this->projectRoot . '/source.zip';
        $this->createZip($this->sourceArchive, 'package/readme.txt', 'valid package');
        $this->package = $this->package('foo/bar', '1.2.3', 'ref-123');
        $this->cache = new PackageCache(
            Configuration::fromRootExtra([
                'persistent-cache' => ['enabled' => true, 'directory' => '.composer-cache', 'packages' => ['foo/bar']],
            ]),
            $this->projectRoot
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    public function testValidArchiveCanBeStoredAndLookedUp(): void
    {
        self::assertTrue($this->cache->storeArchive($this->package, $this->sourceArchive));
        self::assertNotNull($this->cache->findCachedArchive($this->package));
        self::assertSame('valid', $this->cache->validatePackage($this->package)['status']);
    }

    public function testMissingArchiveIsAMiss(): void
    {
        $this->cache->storeArchive($this->package, $this->sourceArchive);
        $archive = $this->cache->findCachedArchive($this->package);
        self::assertNotNull($archive);
        unlink($archive);

        self::assertNull($this->cache->findCachedArchive($this->package));
        self::assertSame('missing', $this->cache->validatePackage($this->package)['status']);
    }

    public function testMissingMetadataIsAMiss(): void
    {
        $this->cache->storeArchive($this->package, $this->sourceArchive);
        $metadata = dirname((string) $this->cache->findCachedArchive($this->package)) . '/metadata.json';
        unlink($metadata);

        self::assertNull($this->cache->findCachedArchive($this->package));
    }

    public function testMetadataIdentityAndChecksumAreValidated(): void
    {
        $this->cache->storeArchive($this->package, $this->sourceArchive);
        $archive = (string) $this->cache->findCachedArchive($this->package);
        $metadataPath = dirname($archive) . '/metadata.json';
        $metadata = json_decode((string) file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);

        $metadata['distReference'] = 'different-reference';
        file_put_contents($metadataPath, json_encode($metadata, JSON_THROW_ON_ERROR));
        self::assertSame('invalid', $this->cache->validatePackage($this->package)['status']);

        $metadata['distReference'] = 'ref-123';
        $metadata['packageVersion'] = '9.9.9';
        file_put_contents($metadataPath, json_encode($metadata, JSON_THROW_ON_ERROR));
        self::assertSame('invalid', $this->cache->validatePackage($this->package)['status']);

        $metadata['packageVersion'] = '1.2.3';
        $metadata['archiveSha256'] = str_repeat('0', 64);
        file_put_contents($metadataPath, json_encode($metadata, JSON_THROW_ON_ERROR));
        self::assertSame('invalid', $this->cache->validatePackage($this->package)['status']);
    }

    public function testVersionAndReferenceProduceDifferentCacheEntries(): void
    {
        $this->cache->storeArchive($this->package, $this->sourceArchive);

        self::assertNull($this->cache->findCachedArchive($this->package('foo/bar', '1.2.4', 'ref-123')));
        self::assertNull($this->cache->findCachedArchive($this->package('foo/bar', '1.2.3', 'other-ref')));
    }

    public function testCorruptZipAndNonAllowlistedPackageAreRejected(): void
    {
        $corrupt = $this->projectRoot . '/corrupt.zip';
        file_put_contents($corrupt, 'not a zip');
        self::assertFalse($this->cache->storeArchive($this->package, $corrupt));
        self::assertFalse($this->cache->storeArchive($this->package('other/package', '1.2.3', 'ref-123'), $this->sourceArchive));
    }

    public function testCachedCorruptZipIsRejectedEvenIfMetadataChecksumMatches(): void
    {
        $this->cache->storeArchive($this->package, $this->sourceArchive);
        $archive = (string) $this->cache->findCachedArchive($this->package);
        file_put_contents($archive, 'not a zip');

        $metadataPath = dirname($archive) . '/metadata.json';
        $metadata = json_decode((string) file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);
        $metadata['archiveSize'] = filesize($archive);
        $metadata['archiveSha256'] = hash_file('sha256', $archive);
        file_put_contents($metadataPath, json_encode($metadata, JSON_THROW_ON_ERROR));

        self::assertNull($this->cache->findCachedArchive($this->package));
        self::assertSame('invalid', $this->cache->validatePackage($this->package)['status']);
    }

    public function testLockedDistributionChecksumIsRequiredWhenAvailable(): void
    {
        $checksum = (string) hash_file('sha1', $this->sourceArchive);
        $matching = $this->package('foo/bar', '1.2.3', 'ref-123', $checksum);
        self::assertTrue($this->cache->storeArchive($matching, $this->sourceArchive));

        $mismatched = $this->package('foo/bar', '1.2.3', 'ref-123', str_repeat('0', 40));
        self::assertNull($this->cache->findCachedArchive($mismatched));
        self::assertSame('invalid', $this->cache->validatePackage($mismatched)['status']);
    }

    public function testSymbolicLinkCacheDirectoryIsRejected(): void
    {
        mkdir($this->projectRoot . '/outside');
        symlink($this->projectRoot . '/outside', $this->projectRoot . '/.composer-cache');

        self::assertNull($this->cache->findCachedArchive($this->package));
        self::assertFalse($this->cache->storeArchive($this->package, $this->sourceArchive));
    }

    public function testRepeatedWritesLeaveAValidEntry(): void
    {
        self::assertTrue($this->cache->storeArchive($this->package, $this->sourceArchive));
        self::assertTrue($this->cache->storeArchive($this->package, $this->sourceArchive));
        self::assertSame('valid', $this->cache->validatePackage($this->package)['status']);
    }

    public function testStaleEntryIsReported(): void
    {
        $staleEntry = $this->projectRoot . '/.composer-cache/packages/old/vendor/' . str_repeat('f', 64);
        mkdir($staleEntry, 0777, true);
        file_put_contents($staleEntry . '/package.zip', 'stale');

        $stale = $this->cache->findStaleEntries([$this->package]);
        self::assertCount(1, $stale);
        self::assertSame(realpath($staleEntry), $stale[0]);
    }

    public function testConcurrentWritesLeaveAValidEntry(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required to exercise concurrent writes.');
        }

        $children = [];
        for ($i = 0; $i < 2; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Could not create a concurrent cache writer.');
            }
            if ($pid === 0) {
                exit($this->cache->storeArchive($this->package, $this->sourceArchive) ? 0 : 1);
            }
            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        self::assertSame('valid', $this->cache->validatePackage($this->package)['status']);
    }

    private function package(string $name, string $version, ?string $reference, ?string $sha1 = null): PackageInterface
    {
        $package = $this->createMock(PackageInterface::class);
        $package->method('getName')->willReturn($name);
        $package->method('getVersion')->willReturn($version);
        $package->method('getDistReference')->willReturn($reference);
        $package->method('getDistType')->willReturn('zip');
        $package->method('getDistSha1Checksum')->willReturn($sha1);

        return $package;
    }

    private function createZip(string $path, string $entry, string $content): void
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString($entry, $content);
        $zip->close();
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            @unlink($directory);

            return;
        }
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $path = $entry->getPathname();
            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
