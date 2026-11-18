<?php

namespace Whilesmart\Skillpack;

use Whilesmart\Skillpack\Exceptions\LimitExceededException;
use Whilesmart\Skillpack\Exceptions\SkillpackException;
use Whilesmart\Skillpack\Exceptions\UnsafePathException;

/**
 * Checks an in-memory file tree and splits it into skill folders the way a folder on disk is
 * read: a folder holding SKILL.md is one skill, otherwise each child folder holding SKILL.md is.
 *
 * @internal
 */
final class Bundle
{
    /**
     * @param  array<array-key, mixed>  $files
     * @return array<string, string>
     */
    public static function checked(array $files, LoadOptions $options): array
    {
        if (count($files) > $options->maxFiles) {
            throw new LimitExceededException("The bundle holds more than {$options->maxFiles} files");
        }
        $checked = [];
        $total = 0;
        foreach ($files as $path => $content) {
            $path = (string) $path;
            if (! Paths::isValid($path)) {
                throw new UnsafePathException("The bundle holds an unsafe path: {$path}");
            }
            if (! is_string($content)) {
                throw new SkillpackException("{$path} must be given as a string of bytes");
            }
            $total += strlen($content);
            if ($total > $options->maxExtractedBytes) {
                throw new LimitExceededException("The bundle is larger than {$options->maxExtractedBytes} bytes");
            }
            $checked[$path] = $content;
        }
        self::assertNoConflicts($checked);

        return self::withoutSkipped($checked);
    }

    /**
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    public static function withoutSkipped(array $files): array
    {
        return array_filter($files, fn (string $path) => ! Paths::isSkipped($path), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param  array<string, string>  $files
     */
    public static function assertNoConflicts(array $files): void
    {
        foreach (array_keys($files) as $path) {
            $parent = $path;
            while (($slash = strrpos($parent, '/')) !== false) {
                $parent = substr($parent, 0, $slash);
                if (isset($files[$parent])) {
                    throw new UnsafePathException("{$parent} is both a file and a folder");
                }
            }
        }
    }

    /**
     * The tree's one top-level folder when nothing else sits at its root, and "." otherwise.
     *
     * @param  array<string, string>  $files
     * @param  array<string, true>  $dirs
     */
    public static function topFolder(array $files, array $dirs = []): string
    {
        $top = [];
        $isDir = [];
        foreach (array_keys($files) as $path) {
            $segments = explode('/', $path, 2);
            $top[$segments[0]] = true;
            $isDir[$segments[0]] = ($isDir[$segments[0]] ?? false) || isset($segments[1]);
        }
        foreach (array_keys($dirs) as $path) {
            if (array_intersect(explode('/', $path), Paths::SKIPPED_ANYWHERE) !== []) {
                continue;
            }
            $first = explode('/', $path, 2)[0];
            $top[$first] = true;
            $isDir[$first] = true;
        }
        if (count($top) !== 1) {
            return '.';
        }
        $name = (string) array_key_first($top);

        return $isDir[$name] ? $name : '.';
    }

    /**
     * @param  array<string, string>  $files
     * @param  array<string, true>  $dirs
     * @return array<string, array<string, string>> skill folder => files relative to it
     */
    public static function skillsIn(array $files, string $dir = '.', array $dirs = []): array
    {
        $dir = Paths::clean($dir);
        if (Paths::escapes($dir)) {
            throw new UnsafePathException("{$dir} is not a folder inside the bundle");
        }
        $under = $dir === '.' ? $files : self::under($files, $dir);
        if ($dir !== '.' && $under === [] && ! isset($dirs[$dir])) {
            throw new SkillpackException("{$dir} is not a folder");
        }
        if (isset($under['SKILL.md'])) {
            return [$dir => $under];
        }

        $children = [];
        foreach (array_keys($under) as $path) {
            $segments = explode('/', $path, 2);
            if (isset($segments[1]) && $segments[1] === 'SKILL.md') {
                $children[$segments[0]] = true;
            }
        }
        $names = array_keys($children);
        sort($names, SORT_STRING);

        $skills = [];
        foreach ($names as $name) {
            $skills[$dir === '.' ? $name : $dir.'/'.$name] = self::under($under, $name);
        }

        return $skills;
    }

    /**
     * @param  array<string, string>  $files
     * @param  array<string, mixed>  $provenance
     * @return list<Skill>
     */
    public static function skills(array $files, array $provenance, string $dir = '.', array $dirs = []): array
    {
        $groups = self::skillsIn($files, $dir, $dirs);
        $skills = [];
        foreach ($groups as $folder => $skillFiles) {
            $folder = (string) $folder;
            if ($folder === '.') {
                $skills[] = new Skill($skillFiles, $provenance);

                continue;
            }
            $base = $provenance;
            unset($base['links_outside'], $base['digest'], $base['index_description']);
            $base['folder'] = basename($folder);
            $root = $provenance['root'] ?? null;
            $base['root'] = is_string($root) && $root !== '' ? $root.'/'.$folder : $folder;
            $skills[] = new Skill($skillFiles, $base);
        }

        return $skills;
    }

    /**
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    private static function under(array $files, string $dir): array
    {
        $prefix = $dir.'/';
        $found = [];
        foreach ($files as $path => $content) {
            if (str_starts_with($path, $prefix)) {
                $found[substr($path, strlen($prefix))] = $content;
            }
        }

        return $found;
    }
}
