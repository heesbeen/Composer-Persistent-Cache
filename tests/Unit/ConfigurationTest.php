<?php

declare(strict_types=1);

namespace HoarseLeg\PersistentCache\Tests\Unit;

use HoarseLeg\PersistentCache\Configuration;
use Composer\Package\PackageInterface;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    public function testMissingConfigurationIsDisabled(): void
    {
        $configuration = Configuration::fromRootExtra([]);

        self::assertFalse($configuration->enabled);
        self::assertSame([], $configuration->getPackages());
    }

    public function testDisabledConfigurationDoesNotAllowPackages(): void
    {
        $configuration = Configuration::fromRootExtra([
            'persistent-cache' => ['enabled' => false, 'directory' => '.composer-cache', 'packages' => ['foo/bar']],
        ]);

        self::assertFalse($configuration->allows($this->package('foo/bar')));
    }

    public function testEmptyAllowlistAllowsNoPackages(): void
    {
        $configuration = Configuration::fromRootExtra([
            'persistent-cache' => ['enabled' => true, 'directory' => '.composer-cache', 'packages' => []],
        ]);

        self::assertFalse($configuration->allows($this->package('foo/bar')));
    }

    public function testPackageNamesAreNormalizedAndAllowlistedExplicitly(): void
    {
        $configuration = Configuration::fromRootExtra([
            'persistent-cache' => ['enabled' => true, 'directory' => '.composer-cache', 'packages' => ['Foo/Bar']],
        ]);

        self::assertSame(['foo/bar'], $configuration->getPackages());
        self::assertTrue($configuration->allows($this->package('FOO/BAR')));
        self::assertFalse($configuration->allows($this->package('foo/other')));
    }

    public function testInvalidConfigurationValuesAreRejected(): void
    {
        $cases = [
            ['enabled' => 1, 'directory' => '.composer-cache', 'packages' => []],
            ['enabled' => true, 'directory' => '../outside', 'packages' => []],
            ['enabled' => true, 'directory' => '/tmp/cache', 'packages' => []],
            ['enabled' => true, 'directory' => '.composer-cache', 'packages' => ['not-a-package']],
            ['enabled' => true, 'directory' => '.composer-cache', 'packages' => ['foo/bar' => true]],
        ];

        foreach ($cases as $case) {
            try {
                Configuration::fromRootExtra(['persistent-cache' => $case]);
                self::fail('Invalid cache configuration was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testIncompleteConfigurationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Configuration::fromRootExtra([
            'persistent-cache' => ['enabled' => true, 'directory' => '.composer-cache'],
        ]);
    }

    private function package(string $name): PackageInterface
    {
        $package = $this->createMock(PackageInterface::class);
        $package->method('getName')->willReturn($name);

        return $package;
    }
}
