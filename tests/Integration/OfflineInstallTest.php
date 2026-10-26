<?php

declare(strict_types=1);

namespace HoarseLeg\PersistentCache\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class OfflineInstallTest extends TestCase
{
    public function testProjectArchiveSupportsOfflineComposerInstallWithoutChangingLock(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for Composer integration tests.');
        }

        $project = sys_get_temp_dir() . '/persistent-cache-offline-' . bin2hex(random_bytes(6));
        $home = $project . '/composer-home';
        mkdir($project, 0777, true);
        mkdir($home);
        $archive = $project . '/package.zip';
        $this->createZip($archive);

        $pluginPath = dirname(__DIR__, 2);
        $composerJson = [
            'name' => 'fixture/offline-project',
            'repositories' => [
                ['type' => 'path', 'url' => $pluginPath, 'options' => ['symlink' => true]],
                [
                    'type' => 'package',
                    'package' => [
                        'name' => 'fixture/cached-package',
                        'version' => '1.0.0',
                        'dist' => [
                            'type' => 'zip',
                            'url' => 'file://' . $archive,
                            'reference' => 'fixture-ref-1',
                            'shasum' => sha1_file($archive),
                        ],
                    ],
                ],
            ],
            'require' => [
                'hoarseleg/composer-persistent-cache' => '*',
                'fixture/cached-package' => '1.0.0',
            ],
            'minimum-stability' => 'dev',
            'config' => [
                'allow-plugins' => ['hoarseleg/composer-persistent-cache' => true],
            ],
            'extra' => [
                'persistent-cache' => [
                    'enabled' => true,
                    'directory' => '.composer-cache',
                    'packages' => ['fixture/cached-package'],
                ],
            ],
        ];
        file_put_contents($project . '/composer.json', json_encode($composerJson, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        try {
            [$code, $output] = $this->runComposer($project, $home, 'update');
            self::assertSame(0, $code, $output);

            $cachedArchives = glob($project . '/.composer-cache/packages/fixture/cached-package/*/package.zip');
            self::assertNotFalse($cachedArchives);
            self::assertCount(1, $cachedArchives, 'The package archive was not saved in the project cache.');

            $lockHash = hash_file('sha256', $project . '/composer.lock');
            self::assertNotFalse($lockHash);

            $this->removeDirectory($project . '/.composer-cache/packages/fixture/cached-package');
            $this->removeDirectory($project . '/vendor/fixture/cached-package');
            [$code, $output] = $this->runComposer($project, $home, 'install');
            self::assertSame(0, $code, $output);
            self::assertFileExists($project . '/.composer-cache/packages/fixture/cached-package/' . basename(dirname($cachedArchives[0])) . '/package.zip');
            self::assertSame($lockHash, hash_file('sha256', $project . '/composer.lock'));

            $this->removeDirectory($project . '/.composer-cache/packages/fixture/cached-package');
            $this->removeDirectory($project . '/vendor/fixture/cached-package');
            $this->removeDirectory($home . '/cache');
            [$code, $output] = $this->runComposer($project, $home, 'install');
            self::assertSame(0, $code, $output);
            self::assertFileExists($project . '/.composer-cache/packages/fixture/cached-package/' . basename(dirname($cachedArchives[0])) . '/package.zip');
            self::assertSame($lockHash, hash_file('sha256', $project . '/composer.lock'));

            [$code, $output] = $this->runComposer($project, $home, 'persistent-cache:validate');
            self::assertSame(0, $code, $output);
            self::assertStringContainsString('1 valid', $output);

            $this->removeDirectory($project . '/vendor/fixture/cached-package');
            rename($archive, $archive . '.offline');
            $this->removeDirectory($home . '/cache');

            [$code, $output] = $this->runComposer($project, $home, 'install');
            self::assertSame(0, $code, $output);
            self::assertFileExists($project . '/vendor/fixture/cached-package/message.txt');
            self::assertSame($lockHash, hash_file('sha256', $project . '/composer.lock'));
            $globalCacheEntries = glob($home . '/cache/files/fixture/cached-package/*');
            self::assertNotFalse($globalCacheEntries);
            self::assertNotEmpty($globalCacheEntries, 'Composer did not create its native local-URL cache entry.');

            $this->removeDirectory($project . '/vendor/fixture/cached-package');
            $this->removeDirectory($project . '/.composer-cache/packages/fixture/cached-package');
            $this->removeDirectory($home . '/cache');
            [$code, $output] = $this->runComposer($project, $home, 'install');
            self::assertNotSame(0, $code, 'Installation unexpectedly succeeded without either archive cache or repository access.');
            self::assertSame($lockHash, hash_file('sha256', $project . '/composer.lock'));
        } finally {
            $this->removeDirectory($project);
        }
    }

    /**
     * @return array{int, string}
     */
    private function runComposer(string $project, string $home, string $command): array
    {
        $arguments = ['composer', $command, '--no-interaction', '--no-scripts'];
        if ($command !== 'persistent-cache:validate') {
            $arguments[] = '--no-progress';
        }
        $process = proc_open(
            $arguments,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $project,
            [
                'COMPOSER_HOME' => $home,
                'COMPOSER_ALLOW_SUPERUSER' => '1',
                'PATH' => (string) getenv('PATH'),
                'HOME' => (string) getenv('HOME'),
            ]
        );
        if (!is_resource($process)) {
            self::fail('Could not start Composer.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout . $stderr];
    }

    private function createZip(string $path): void
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('package/message.txt', 'installed from project cache');
        $zip->close();
    }

    private function removeDirectory(string $directory): void
    {
        if (!file_exists($directory) && !is_link($directory)) {
            return;
        }
        if (is_link($directory) || !is_dir($directory)) {
            unlink($directory);

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
