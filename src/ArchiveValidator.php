<?php

declare(strict_types=1);

namespace HoarseLeg\PersistentCache;

final class ArchiveValidator
{
    public function isValidZip(string $path): bool
    {
        if (!is_file($path) || is_link($path) || filesize($path) === 0) {
            return false;
        }

        $archive = new \ZipArchive();
        $result = $archive->open($path, \ZipArchive::CHECKCONS);
        if ($result !== true) {
            return false;
        }

        $valid = $archive->numFiles > 0;
        $archive->close();

        return $valid;
    }
}
