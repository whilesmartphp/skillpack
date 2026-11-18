<?php

namespace Whilesmart\Skillpack;

use Whilesmart\Skillpack\Exceptions\UnsafePathException;

final class Paths
{
    public const MAX_LENGTH = 1024;

    public const SKIPPED_ANYWHERE = ['__pycache__', 'node_modules'];

    public const SKIPPED_AT_ROOT = ['evals'];

    public static function isValid(string $path): bool
    {
        if ($path === '' || strlen($path) > self::MAX_LENGTH) {
            return false;
        }
        if (! mb_check_encoding($path, 'UTF-8') || preg_match('/[\x00-\x1f\x7f\\\\:]/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    public static function assertValid(string $path): string
    {
        if (! self::isValid($path)) {
            throw new UnsafePathException("{$path} is not a safe relative file path");
        }

        return $path;
    }

    public static function clean(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($parts !== [] && end($parts) !== '..') {
                    array_pop($parts);

                    continue;
                }
                $parts[] = '..';

                continue;
            }
            $parts[] = $segment;
        }

        return $parts === [] ? '.' : implode('/', $parts);
    }

    public static function escapes(string $cleaned): bool
    {
        return $cleaned === '..' || str_starts_with($cleaned, '../');
    }

    public static function isSkipped(string $path): bool
    {
        $folders = explode('/', $path);
        array_pop($folders);
        foreach ($folders as $segment) {
            if (in_array($segment, self::SKIPPED_ANYWHERE, true)) {
                return true;
            }
        }

        return false;
    }
}
