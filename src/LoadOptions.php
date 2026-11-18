<?php

namespace Whilesmart\Skillpack;

use Whilesmart\Skillpack\Contracts\HostResolver;
use Whilesmart\Skillpack\Contracts\HttpTransport;
use Whilesmart\Skillpack\Exceptions\InvalidOptionsException;

final class LoadOptions
{
    public const DEFAULT_MAX_DOWNLOAD_BYTES = 32 * 1024 * 1024;

    public const DEFAULT_MAX_EXTRACTED_BYTES = 128 * 1024 * 1024;

    public const DEFAULT_MAX_FILES = 5000;

    public const DEFAULT_MAX_SKILLS = 500;

    public const DEFAULT_TIMEOUT_SECONDS = 60;

    public const MAX_REDIRECTS = 10;

    /** @var list<string> */
    public readonly array $allowedHosts;

    /** @var list<string> */
    public readonly array $allowedRoots;

    public readonly string $directory;

    /**
     * @param  array<string>  $allowedHosts  Host names remote skills, indexes and redirects may use. Empty denies all remote access.
     * @param  array<string>  $allowedRoots  Folders local paths must stay inside. Empty allows any readable folder.
     * @param  string  $directory  Folder inside an archive given directly by URL that holds the skills.
     */
    public function __construct(
        array $allowedHosts = [],
        array $allowedRoots = [],
        public readonly int $maxDownloadBytes = self::DEFAULT_MAX_DOWNLOAD_BYTES,
        public readonly int $maxExtractedBytes = self::DEFAULT_MAX_EXTRACTED_BYTES,
        public readonly int $maxFiles = self::DEFAULT_MAX_FILES,
        public readonly int $maxSkills = self::DEFAULT_MAX_SKILLS,
        public readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        public readonly bool $allowPrivateAddresses = false,
        string $directory = '',
        public readonly ?HttpTransport $transport = null,
        public readonly ?HostResolver $resolver = null,
    ) {
        foreach ([
            'maxDownloadBytes' => $maxDownloadBytes,
            'maxExtractedBytes' => $maxExtractedBytes,
            'maxFiles' => $maxFiles,
            'maxSkills' => $maxSkills,
            'timeoutSeconds' => $timeoutSeconds,
        ] as $name => $value) {
            if ($value <= 0) {
                throw new InvalidOptionsException("{$name} must be greater than zero");
            }
        }

        $hosts = [];
        foreach ($allowedHosts as $host) {
            if (! is_string($host) || trim($host) === '') {
                throw new InvalidOptionsException('allowedHosts must hold host names');
            }
            $host = strtolower(trim($host));
            if (str_contains($host, '/') || str_contains($host, '@') || preg_match('/\s/', $host)) {
                throw new InvalidOptionsException("{$host} is not a host name");
            }
            $hosts[$host] = $host;
        }
        $this->allowedHosts = array_values($hosts);

        $roots = [];
        foreach ($allowedRoots as $root) {
            if (! is_string($root) || trim($root) === '' || str_contains($root, "\0")) {
                throw new InvalidOptionsException('allowedRoots must hold folder paths');
            }
            $roots[] = $root;
        }
        $this->allowedRoots = $roots;

        $directory = trim(str_replace('\\', '/', $directory), '/');
        if ($directory !== '' && ! Paths::isValid($directory)) {
            throw new InvalidOptionsException("{$directory} is not a folder path inside an archive");
        }
        $this->directory = $directory;
    }

    public function allowsHost(string $host): bool
    {
        return in_array(strtolower($host), $this->allowedHosts, true);
    }
}
