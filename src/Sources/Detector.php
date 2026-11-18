<?php

namespace Whilesmart\Skillpack\Sources;

/**
 * Names what a download holds: the Content-Type first, then the URL's extension, then the
 * leading bytes. text/plain and application/octet-stream say nothing, so they fall through.
 *
 * @internal
 */
final class Detector
{
    public const INDEX = 'index';

    public const SKILL_MD = 'skill-md';

    public const ZIP = 'zip';

    public const TAR_GZIP = 'tar-gz';

    public const UNKNOWN = 'unknown';

    public static function detect(string $contentType, string $path, string $data): string
    {
        $media = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($media === 'application/json' || str_ends_with($media, '+json')) {
            return self::INDEX;
        }
        if ($media === 'text/markdown' || $media === 'text/x-markdown') {
            return self::SKILL_MD;
        }
        if ($media === 'application/zip' || $media === 'application/x-zip-compressed') {
            return self::ZIP;
        }
        if (in_array($media, ['application/gzip', 'application/x-gzip', 'application/x-gtar', 'application/x-compressed-tar'], true)) {
            return self::TAR_GZIP;
        }

        $name = strtolower($path);
        if (str_ends_with($name, '.json')) {
            return self::INDEX;
        }
        if (str_ends_with($name, '.md')) {
            return self::SKILL_MD;
        }
        if (str_ends_with($name, '.zip')) {
            return self::ZIP;
        }
        if (str_ends_with($name, '.tar.gz') || str_ends_with($name, '.tgz')) {
            return self::TAR_GZIP;
        }

        if (str_starts_with($data, "PK\x03\x04") || str_starts_with($data, "PK\x05\x06")) {
            return self::ZIP;
        }
        if (str_starts_with($data, "\x1f\x8b")) {
            return self::TAR_GZIP;
        }
        $lead = ltrim(str_starts_with($data, "\xEF\xBB\xBF") ? substr($data, 3) : $data, " \t\r\n");
        if (str_starts_with($lead, '{')) {
            return self::INDEX;
        }
        if (str_starts_with($lead, '---')) {
            return self::SKILL_MD;
        }

        return self::UNKNOWN;
    }
}
