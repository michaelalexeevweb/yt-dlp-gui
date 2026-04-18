<?php

declare(strict_types=1);

namespace YtDlpGui\Download;

use function getenv;
use function is_string;
use function rtrim;

final class DownloadsDirectoryResolver
{
    public static function defaultForCurrentUser(): string
    {
        $homeDirectory = getenv('HOME');

        if (!is_string($homeDirectory) || $homeDirectory === '') {
            return '/downloads';
        }

        return rtrim($homeDirectory, '/') . '/Downloads';
    }
}

