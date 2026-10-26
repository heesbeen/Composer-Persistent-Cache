<?php

declare(strict_types=1);

namespace HoarseLeg\PersistentCache;

use Composer\Package\PackageInterface;

final class CacheKey
{
    public static function forPackage(PackageInterface $package): string
    {
        $parts = [
            strtolower($package->getName()),
            $package->getVersion(),
            $package->getDistReference() ?? '',
        ];

        return hash('sha256', implode("\0", $parts));
    }
}
