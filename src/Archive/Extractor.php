<?php

namespace Whilesmart\Skillpack\Archive;

use Whilesmart\Skillpack\Bundle;
use Whilesmart\Skillpack\Exceptions\LimitExceededException;
use Whilesmart\Skillpack\Exceptions\SkillpackException;
use Whilesmart\Skillpack\Exceptions\UnsafePathException;
use Whilesmart\Skillpack\LoadOptions;
use Whilesmart\Skillpack\Paths;

/**
 * Reads a .zip or .tar.gz archive in memory under the entry and unpacked size limits. Unsafe
 * paths and links that leave the archive refuse the whole archive; links that stay inside are
 * left out, not followed. Nothing is written to disk.
 */
final class Extractor
{
    public const ZIP = 'zip';

    public const TAR_GZIP = 'tar-gz';

    private const CHUNK = 1024;

    private const MAX_LINK_BYTES = 4096;

    private const MAX_HEADER_BYTES = 1048576;

    /** @var array<string, string> */
    private array $files = [];

    /** @var array<string, true> */
    private array $dirs = [];

    private int $total = 0;

    private int $count = 0;

    private function __construct(private readonly LoadOptions $options) {}

    /**
     * @return array{files: array<string, string>, dirs: array<string, true>}
     */
    public static function extract(string $data, string $kind, LoadOptions $options): array
    {
        $extractor = new self($options);
        match ($kind) {
            self::ZIP => $extractor->readZip($data),
            self::TAR_GZIP => $extractor->readTar($extractor->gunzip($data)),
            default => throw new SkillpackException('it is not a .zip or .tar.gz archive'),
        };
        Bundle::assertNoConflicts($extractor->files);

        return ['files' => Bundle::withoutSkipped($extractor->files), 'dirs' => $extractor->dirs];
    }

    private function name(string $raw): string
    {
        $this->count++;
        if ($this->count > $this->options->maxFiles) {
            throw new LimitExceededException("the archive holds more than {$this->options->maxFiles} entries");
        }
        $name = str_starts_with($raw, './') ? substr($raw, 2) : $raw;
        $name = str_ends_with($name, '/') ? substr($name, 0, -1) : $name;
        if ($name === '' || $name === '.') {
            return '';
        }
        if (str_contains($raw, '\\') || ! Paths::isValid($name)) {
            throw new UnsafePathException("the archive holds an unsafe path: {$raw}");
        }

        return $name;
    }

    private function link(string $name, string $target, bool $hard): void
    {
        $slash = strrpos($name, '/');
        $base = $slash === false ? '.' : substr($name, 0, $slash);
        $resolved = $hard ? Paths::clean($target) : Paths::clean($base.'/'.$target);
        if (str_starts_with($target, '/') || str_contains($target, '\\') || Paths::escapes($resolved)) {
            throw new UnsafePathException("the archive links {$name} to {$target}, outside the archive");
        }
    }

    private function reserve(string $name, int $size): void
    {
        if ($size < 0 || $this->total + $size > $this->options->maxExtractedBytes) {
            throw new LimitExceededException("the archive unpacks to more than {$this->options->maxExtractedBytes} bytes");
        }
        if (isset($this->files[$name])) {
            throw new UnsafePathException("the archive holds {$name} twice");
        }
    }

    private function store(string $name, int $size, string $content): void
    {
        if (strlen($content) !== $size) {
            throw new SkillpackException("the archive entry {$name} is not the size it declares");
        }
        $this->total += $size;
        $this->files[$name] = $content;
    }

    private function readZip(string $data): void
    {
        $end = $this->endOfCentralDirectory($data);
        $record = unpack('vdisk/vcdDisk/vdiskEntries/ventries/Vsize/Voffset', substr($data, $end + 4, 16));
        if ($record === false) {
            throw new SkillpackException('it is not a readable zip archive');
        }
        if ($record['entries'] === 0xFFFF || $record['size'] === 0xFFFFFFFF || $record['offset'] === 0xFFFFFFFF) {
            throw new SkillpackException('zip64 archives are not supported');
        }
        if ($record['disk'] !== 0 || $record['cdDisk'] !== 0 || $end < $record['offset'] + $record['size']) {
            throw new SkillpackException('it is not a readable zip archive');
        }

        $position = $record['offset'];
        for ($index = 0; $index < $record['entries']; $index++) {
            if ($position + 46 > $end || substr($data, $position, 4) !== "PK\x01\x02") {
                throw new SkillpackException('it is not a readable zip archive');
            }
            $header = unpack(
                'vmadeBy/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnameLength/vextraLength/vcommentLength/vdiskStart/vinternal/Vexternal/Vlocal',
                substr($data, $position + 4, 42),
            );
            if ($header === false) {
                throw new SkillpackException('it is not a readable zip archive');
            }
            $raw = substr($data, $position + 46, $header['nameLength']);
            $position += 46 + $header['nameLength'] + $header['extraLength'] + $header['commentLength'];
            if ($position > $end) {
                throw new SkillpackException('it is not a readable zip archive');
            }

            $name = $this->name($raw);
            if ($name === '') {
                continue;
            }

            [$isDir, $isLink, $isRegular] = $this->zipType($header['madeBy'], $header['external'], $raw);
            if ($isDir) {
                $this->dirs[$name] = true;

                continue;
            }
            if (! $isLink && ! $isRegular) {
                continue;
            }
            if (($header['flags'] & 1) === 1) {
                throw new SkillpackException("the archive entry {$name} is encrypted");
            }
            if ($isLink) {
                $this->link($name, $this->zipEntry($data, $header, $name, self::MAX_LINK_BYTES, false), false);

                continue;
            }
            $this->reserve($name, $header['uncompressed']);
            $this->store($name, $header['uncompressed'], $this->zipEntry($data, $header, $name, $header['uncompressed'] + 1, true));
        }
    }

    private function endOfCentralDirectory(string $data): int
    {
        $length = strlen($data);
        $floor = max(0, $length - 22 - 65535);
        for ($position = $length - 22; $position >= $floor; $position--) {
            if (substr($data, $position, 4) === "PK\x05\x06") {
                return $position;
            }
        }

        throw new SkillpackException('it is not a readable zip archive');
    }

    /**
     * @return array{0: bool, 1: bool, 2: bool} directory, symbolic link, regular file
     */
    private function zipType(int $madeBy, int $external, string $raw): array
    {
        $creator = $madeBy >> 8;
        $isDir = str_ends_with($raw, '/');
        if (in_array($creator, [3, 19], true)) {
            $type = ($external >> 16) & 0xF000;
            if ($type === 0x4000) {
                return [true, false, false];
            }
            if ($type === 0xA000) {
                return [false, true, false];
            }
            if ($isDir) {
                return [true, false, false];
            }

            return [false, false, $type === 0x8000 || $type === 0];
        }
        if (in_array($creator, [0, 11, 14], true) && ($external & 0x10) === 0x10) {
            $isDir = true;
        }

        return [$isDir, false, ! $isDir];
    }

    /**
     * @param  array<string, int>  $header
     */
    private function zipEntry(string $data, array $header, string $name, int $limit, bool $verify): string
    {
        $local = $header['local'];
        if ($local + 30 > strlen($data) || substr($data, $local, 4) !== "PK\x03\x04") {
            throw new SkillpackException("the archive entry {$name} is not readable");
        }
        $lengths = unpack('vname/vextra', substr($data, $local + 26, 4));
        if ($lengths === false) {
            throw new SkillpackException("the archive entry {$name} is not readable");
        }
        $start = $local + 30 + $lengths['name'] + $lengths['extra'];
        if ($start + $header['compressed'] > strlen($data)) {
            throw new SkillpackException("the archive entry {$name} is not the size it declares");
        }
        $compressed = substr($data, $start, $header['compressed']);

        $content = match ($header['method']) {
            0 => substr($compressed, 0, $limit),
            8 => $this->inflate($compressed, ZLIB_ENCODING_RAW, $limit, $name),
            default => throw new SkillpackException("the archive entry {$name} uses compression method {$header['method']}, which is not supported"),
        };

        if ($verify) {
            if (strlen($content) !== $header['uncompressed']) {
                throw new SkillpackException("the archive entry {$name} is not the size it declares");
            }
            if (hash('crc32b', $content) !== sprintf('%08x', $header['crc'])) {
                throw new SkillpackException("the archive entry {$name} fails its checksum");
            }
        }

        return $content;
    }

    private function gunzip(string $data): string
    {
        $limit = $this->options->maxExtractedBytes + ($this->options->maxFiles + 2) * 1536 + self::MAX_HEADER_BYTES;
        $tar = $this->inflate($data, ZLIB_ENCODING_GZIP, $limit + 1, 'archive');
        if (strlen($tar) > $limit) {
            throw new LimitExceededException("the archive unpacks to more than {$this->options->maxExtractedBytes} bytes");
        }

        return $tar;
    }

    private function inflate(string $data, int $encoding, int $limit, string $name): string
    {
        set_error_handler(fn () => true);
        try {
            $context = inflate_init($encoding);
            if ($context === false) {
                throw new SkillpackException("the archive entry {$name} could not be decompressed");
            }
            $output = '';
            $length = strlen($data);
            for ($offset = 0; $offset < $length; $offset += self::CHUNK) {
                $chunk = inflate_add($context, substr($data, $offset, self::CHUNK), ZLIB_SYNC_FLUSH);
                if ($chunk === false) {
                    throw new SkillpackException("the archive entry {$name} is corrupt");
                }
                $output .= $chunk;
                if (strlen($output) >= $limit) {
                    return substr($output, 0, $limit);
                }
                if (inflate_get_status($context) === ZLIB_STREAM_END) {
                    break;
                }
            }
            if (inflate_get_status($context) !== ZLIB_STREAM_END) {
                $chunk = inflate_add($context, '', ZLIB_FINISH);
                if ($chunk === false || inflate_get_status($context) !== ZLIB_STREAM_END) {
                    throw new SkillpackException("the archive entry {$name} is corrupt");
                }
                $output .= $chunk;
            }
        } finally {
            restore_error_handler();
        }

        return strlen($output) > $limit ? substr($output, 0, $limit) : $output;
    }

    private function readTar(string $tar): void
    {
        $length = strlen($tar);
        $position = 0;
        $pax = [];
        $longName = null;
        $longLink = null;

        while ($position < $length) {
            if ($position + 512 > $length) {
                throw new SkillpackException('the tar archive is truncated');
            }
            $block = substr($tar, $position, 512);
            $position += 512;
            if ($block === str_repeat("\0", 512)) {
                return;
            }
            $this->verifyChecksum($block);

            $name = self::cString(substr($block, 0, 100));
            $size = self::number(substr($block, 124, 12));
            $type = $block[156];
            $linkName = self::cString(substr($block, 157, 100));
            if (substr($block, 257, 6) === "ustar\0") {
                $prefix = self::cString(substr($block, 345, 155));
                if ($prefix !== '') {
                    $name = $prefix.'/'.$name;
                }
            }

            if (in_array($type, ['x', 'g', 'L', 'K'], true)) {
                if ($size > self::MAX_HEADER_BYTES || $position + $size > $length) {
                    throw new SkillpackException('the tar archive holds an unreadable header');
                }
                $payload = substr($tar, $position, $size);
                $position += self::padded($size);
                match ($type) {
                    'x' => $pax = self::paxRecords($payload),
                    'L' => $longName = self::cString($payload),
                    'K' => $longLink = self::cString($payload),
                    default => null,
                };

                continue;
            }

            if ($longName !== null) {
                $name = $longName;
            }
            if ($longLink !== null) {
                $linkName = $longLink;
            }
            if (isset($pax['path'])) {
                $name = $pax['path'];
            }
            if (isset($pax['linkpath'])) {
                $linkName = $pax['linkpath'];
            }
            if (isset($pax['size'])) {
                if (! preg_match('/^\d{1,18}$/D', $pax['size'])) {
                    throw new SkillpackException('the tar archive holds an unreadable header');
                }
                $size = (int) $pax['size'];
            }
            $pax = [];
            $longName = null;
            $longLink = null;

            $isDir = $type === '5' || ($type === "\0" && str_ends_with($name, '/'));
            $hasData = ! $isDir && ! in_array($type, ['1', '2', '3', '4', '5', '6'], true);
            $dataStart = $position;
            $position += $hasData ? self::padded($size) : 0;

            $entry = $this->name($name);
            if ($entry === '') {
                continue;
            }
            if ($isDir) {
                $this->dirs[$entry] = true;

                continue;
            }
            if ($type === '1' || $type === '2') {
                $this->link($entry, $linkName, $type === '1');

                continue;
            }
            if ($type !== '0' && $type !== "\0") {
                continue;
            }
            $this->reserve($entry, $size);
            $available = max(0, min($size, $length - $dataStart));
            $this->store($entry, $size, substr($tar, $dataStart, $available));
        }
    }

    private function verifyChecksum(string $block): void
    {
        $expected = self::number(substr($block, 148, 8));
        $sum = 0;
        for ($index = 0; $index < 512; $index++) {
            $sum += ($index >= 148 && $index < 156) ? 32 : ord($block[$index]);
        }
        if ($sum !== $expected) {
            throw new SkillpackException('it is not a readable tar archive');
        }
    }

    private static function cString(string $field): string
    {
        $end = strpos($field, "\0");

        return $end === false ? $field : substr($field, 0, $end);
    }

    private static function number(string $field): int
    {
        if ($field !== '' && (ord($field[0]) & 0x80) === 0x80) {
            if ((ord($field[0]) & 0x40) === 0x40) {
                throw new SkillpackException('the tar archive holds a negative size');
            }
            $value = ord($field[0]) & 0x3F;
            for ($index = 1, $count = strlen($field); $index < $count; $index++) {
                if ($value > intdiv(PHP_INT_MAX, 256)) {
                    throw new SkillpackException('the tar archive holds an unreadable size');
                }
                $value = ($value << 8) | ord($field[$index]);
            }

            return $value;
        }
        $digits = trim($field, " \0");
        if ($digits === '') {
            return 0;
        }
        if (! preg_match('/^[0-7]{1,20}$/D', $digits)) {
            throw new SkillpackException('the tar archive holds an unreadable header');
        }
        $value = octdec($digits);
        if (! is_int($value)) {
            throw new SkillpackException('the tar archive holds an unreadable size');
        }

        return $value;
    }

    private static function padded(int $size): int
    {
        return (int) (ceil($size / 512) * 512);
    }

    /**
     * @return array<string, string>
     */
    private static function paxRecords(string $payload): array
    {
        $records = [];
        $position = 0;
        $length = strlen($payload);
        while ($position < $length) {
            if (! preg_match('/\G(\d+) /', $payload, $match, 0, $position)) {
                throw new SkillpackException('the tar archive holds an unreadable header');
            }
            $size = (int) $match[1];
            if ($size <= strlen($match[0]) || $position + $size > $length || $payload[$position + $size - 1] !== "\n") {
                throw new SkillpackException('the tar archive holds an unreadable header');
            }
            $record = substr($payload, $position + strlen($match[0]), $size - strlen($match[0]) - 1);
            $position += $size;
            $equals = strpos($record, '=');
            if ($equals === false) {
                throw new SkillpackException('the tar archive holds an unreadable header');
            }
            $records[substr($record, 0, $equals)] = substr($record, $equals + 1);
        }

        return $records;
    }
}
