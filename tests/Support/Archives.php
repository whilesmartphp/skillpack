<?php

namespace Whilesmart\Skillpack\Tests\Support;

/**
 * Builds .zip and .tar.gz archives in memory for tests.
 */
final class Archives
{
    /**
     * @param  array<string, string>  $files
     * @param  array<string, string>  $links  name => symbolic link target
     */
    public static function zip(array $files, array $links = [], bool $deflate = true): string
    {
        $entries = [];
        foreach ($files as $name => $content) {
            $entries[] = [(string) $name, $content, false];
        }
        foreach ($links as $name => $target) {
            $entries[] = [(string) $name, $target, true];
        }

        $data = '';
        $central = '';
        foreach ($entries as [$name, $content, $isLink]) {
            $method = $deflate && ! $isLink ? 8 : 0;
            $compressed = $method === 8 ? (string) gzdeflate($content) : $content;
            $crc = crc32($content);
            $offset = strlen($data);
            $data .= pack('VvvvvvVVVvv', 0x04034B50, 20, 0, $method, 0, 0x21, $crc, strlen($compressed), strlen($content), strlen($name), 0)
                .$name.$compressed;
            $mode = $isLink ? (0xA000 | 0777) : (0x8000 | 0644);
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, (3 << 8) | 20, 20, 0, $method, 0, 0x21, $crc, strlen($compressed), strlen($content), strlen($name), 0, 0, 0, 0, $mode << 16, $offset)
                .$name;
        }

        return $data.$central.pack('VvvvvVVv', 0x06054B50, 0, 0, count($entries), count($entries), strlen($central), strlen($data), 0);
    }

    /**
     * @param  array<string, string>  $files
     * @param  array<string, string>  $links  name => symbolic link target
     * @param  array<string, string>  $hardLinks  name => hard link target
     */
    public static function tarGz(array $files, array $links = [], array $hardLinks = []): string
    {
        $tar = '';
        foreach ($files as $name => $content) {
            $tar .= self::header((string) $name, strlen($content), '0', '');
            $tar .= str_pad($content, (int) (ceil(strlen($content) / 512) * 512), "\0");
        }
        foreach ($links as $name => $target) {
            $tar .= self::header((string) $name, 0, '2', $target);
        }
        foreach ($hardLinks as $name => $target) {
            $tar .= self::header((string) $name, 0, '1', $target);
        }
        $tar .= str_repeat("\0", 1024);

        return (string) gzencode($tar);
    }

    private static function header(string $name, int $size, string $type, string $link): string
    {
        $header = str_pad($name, 100, "\0")
            .str_pad(sprintf('%07o', 0644), 8, "\0")
            .str_pad(sprintf('%07o', 0), 8, "\0")
            .str_pad(sprintf('%07o', 0), 8, "\0")
            .str_pad(sprintf('%011o', $size), 12, "\0")
            .str_pad(sprintf('%011o', 0), 12, "\0")
            .'        '
            .$type
            .str_pad($link, 100, "\0")
            ."ustar\0"
            .'00'
            .str_repeat("\0", 247);

        $sum = 0;
        for ($index = 0; $index < 512; $index++) {
            $sum += ord($header[$index]);
        }

        return substr_replace($header, sprintf('%06o', $sum)."\0 ", 148, 8);
    }
}
