<?php

namespace Whilesmart\Skillpack\Sources;

use Whilesmart\Skillpack\Contracts\SkillSource;
use Whilesmart\Skillpack\Exceptions\AccessDeniedException;
use Whilesmart\Skillpack\Exceptions\LimitExceededException;
use Whilesmart\Skillpack\Exceptions\SkillpackException;
use Whilesmart\Skillpack\Exceptions\UnsafePathException;
use Whilesmart\Skillpack\LoadOptions;
use Whilesmart\Skillpack\Pack;
use Whilesmart\Skillpack\Paths;
use Whilesmart\Skillpack\Skill;

/**
 * Skills from a folder on disk, read into memory. A folder holding SKILL.md is one skill;
 * otherwise each child folder holding SKILL.md is. Symbolic links are followed only while they
 * stay inside the skill folder; links that leave it are recorded and never read.
 */
final class LocalSource implements SkillSource
{
    private readonly LoadOptions $options;

    private int $fileCount = 0;

    private int $byteCount = 0;

    public function __construct(private readonly string $location, ?LoadOptions $options = null)
    {
        $this->options = $options ?? new LoadOptions;
    }

    public function load(): Pack
    {
        $this->fileCount = 0;
        $this->byteCount = 0;

        if ($this->location === '' || str_contains($this->location, "\0")) {
            throw new SkillpackException('A skills folder path is required');
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:\/\//', $this->location)) {
            throw new SkillpackException("{$this->location} is not an http or https address");
        }

        $real = realpath($this->location);
        if ($real === false) {
            throw new SkillpackException("{$this->location} is not a folder");
        }
        $display = $this->location;
        $folder = basename(self::absolute($this->location));
        if (is_file($real) && basename($real) === 'SKILL.md') {
            $real = dirname($real);
            $display = dirname($this->location);
            $folder = basename(dirname(self::absolute($this->location)));
        }
        if (! is_dir($real)) {
            throw new SkillpackException("{$this->location} is not a folder");
        }
        $this->assertAllowed($real);

        if (is_file($real.DIRECTORY_SEPARATOR.'SKILL.md')) {
            return new Pack([$this->skill($real, $display, $folder)]);
        }

        $entries = scandir($real);
        if ($entries === false) {
            throw new SkillpackException("{$this->location} is not a folder");
        }
        sort($entries, SORT_STRING);

        $skills = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->countEntry();
            $child = $real.DIRECTORY_SEPARATOR.$entry;
            if (! is_dir($child) || ! is_file($child.DIRECTORY_SEPARATOR.'SKILL.md')) {
                continue;
            }
            $childReal = realpath($child);
            if ($childReal === false) {
                continue;
            }
            $this->assertAllowed($childReal);
            if (count($skills) >= $this->options->maxSkills) {
                throw new LimitExceededException("{$this->location} holds more than {$this->options->maxSkills} skills");
            }
            $skills[] = $this->skill($childReal, rtrim($display, '/\\').DIRECTORY_SEPARATOR.$entry, $entry);
        }

        return new Pack($skills);
    }

    private function countEntry(): void
    {
        if (++$this->fileCount > $this->options->maxFiles) {
            throw new LimitExceededException("{$this->location} holds more than {$this->options->maxFiles} filesystem entries");
        }
    }

    private function skill(string $root, string $display, string $folder): Skill
    {
        $files = [];
        $outside = [];
        $visited = [$root => true];
        $this->walk($root, $root, '', $files, $outside, $visited);

        $provenance = ['type' => 'local', 'root' => $display, 'folder' => $folder];
        if ($outside !== []) {
            sort($outside, SORT_STRING);
            $provenance['links_outside'] = $outside;
        }

        return new Skill($files, $provenance);
    }

    /**
     * @param  array<string, string>  $files
     * @param  list<string>  $outside
     * @param  array<string, true>  $visited
     */
    private function walk(string $root, string $dir, string $relative, array &$files, array &$outside, array &$visited): void
    {
        $entries = scandir($dir);
        if ($entries === false) {
            throw new SkillpackException("{$dir} could not be read");
        }
        sort($entries, SORT_STRING);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->countEntry();
            $path = $relative === '' ? $entry : $relative.'/'.$entry;
            $full = $dir.DIRECTORY_SEPARATOR.$entry;
            $real = realpath($full);
            if ($real === false) {
                continue;
            }
            if (! self::within($root, $real)) {
                $outside[] = $path;

                continue;
            }
            if (is_dir($real)) {
                if (in_array($entry, Paths::SKIPPED_ANYWHERE, true) || isset($visited[$real])) {
                    continue;
                }
                $visited[$real] = true;
                $this->walk($root, $real, $path, $files, $outside, $visited);

                continue;
            }
            if (! is_file($real)) {
                continue;
            }
            if (! Paths::isValid($path)) {
                throw new UnsafePathException("{$full} does not have a safe relative file name");
            }
            $size = filesize($real);
            if ($size === false || $this->byteCount + $size > $this->options->maxExtractedBytes) {
                throw new LimitExceededException("{$this->location} holds more than {$this->options->maxExtractedBytes} bytes");
            }
            $content = file_get_contents($real, false, null, 0, $size + 1);
            if ($content === false || strlen($content) !== $size) {
                throw new SkillpackException("{$full} could not be read");
            }
            $this->byteCount += $size;
            $files[$path] = $content;
        }
    }

    private function assertAllowed(string $real): void
    {
        if ($this->options->allowedRoots === []) {
            return;
        }
        foreach ($this->options->allowedRoots as $root) {
            $allowed = realpath($root);
            if ($allowed !== false && self::within($allowed, $real)) {
                return;
            }
        }

        throw new AccessDeniedException("{$this->location} is outside the allowed skill folders");
    }

    private static function within(string $root, string $target): bool
    {
        if ($target === $root) {
            return true;
        }
        $prefix = rtrim($root, '/\\').DIRECTORY_SEPARATOR;

        return str_starts_with($target, $prefix);
    }

    private static function absolute(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (! str_starts_with($path, '/') && ! preg_match('/^[A-Za-z]:\//', $path)) {
            $path = str_replace('\\', '/', (string) getcwd()).'/'.$path;
        }
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        return '/'.implode('/', $parts);
    }
}
