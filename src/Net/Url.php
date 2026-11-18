<?php

namespace Whilesmart\Skillpack\Net;

use Whilesmart\Skillpack\Exceptions\SkillpackException;

/**
 * An http or https address, parsed with the RFC 3986 grammar and rebuilt from checked parts
 * so the transport never sees a string it could read differently.
 */
final class Url
{
    private const REFERENCE = '/^(?:([^:\/?#]+):)?(?:\/\/([^\/?#]*))?([^?#]*)(?:\?([^#]*))?(?:#(.*))?$/sD';

    private function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly ?int $port,
        public readonly string $path,
        public readonly ?string $query,
    ) {}

    public static function isWeb(string $location): bool
    {
        return (bool) preg_match('/^https?:\/\//i', $location);
    }

    public static function parse(string $location): self
    {
        $parts = self::split($location);
        if ($parts === null || $parts['scheme'] === null || $parts['authority'] === null) {
            throw self::invalid($location);
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw self::invalid($location);
        }
        [$host, $port] = self::parseAuthority($parts['authority'], $location);

        return new self($scheme, $host, $port, self::path(self::removeDots($parts['path'] === '' ? '/' : $parts['path']), $location), self::query($parts['query'], $location));
    }

    /**
     * Resolves a reference against this address (RFC 3986 section 5.2).
     */
    public function resolve(string $reference): self
    {
        $parts = self::split($reference);
        if ($parts === null) {
            throw self::invalid($reference);
        }
        if ($parts['scheme'] !== null) {
            return self::parse($reference);
        }
        if ($parts['authority'] !== null) {
            return self::parse($this->scheme.':'.$reference);
        }
        if ($parts['path'] === '') {
            $path = $this->path;
            $query = $parts['query'] ?? $this->query;
        } else {
            $path = str_starts_with($parts['path'], '/')
                ? $parts['path']
                : substr($this->path, 0, (int) strrpos($this->path, '/') + 1).$parts['path'];
            $query = $parts['query'];
        }

        return new self($this->scheme, $this->host, $this->port, self::path(self::removeDots($path), $reference), self::query($query, $reference));
    }

    public function withPath(string $path): self
    {
        return new self($this->scheme, $this->host, $this->port, self::path(self::removeDots($path), $path), null);
    }

    public function effectivePort(): int
    {
        return $this->port ?? ($this->scheme === 'https' ? 443 : 80);
    }

    public function authority(): string
    {
        $host = str_contains($this->host, ':') ? '['.$this->host.']' : $this->host;

        return $this->port === null ? $host : $host.':'.$this->port;
    }

    public function __toString(): string
    {
        return $this->scheme.'://'.$this->authority().$this->path.($this->query === null ? '' : '?'.$this->query);
    }

    /**
     * @return array{scheme: ?string, authority: ?string, path: string, query: ?string}|null
     */
    private static function split(string $reference): ?array
    {
        if (preg_match('/[\x00-\x20\x7f]/', $reference) || ! preg_match(self::REFERENCE, $reference, $match, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        return ['scheme' => $match[1], 'authority' => $match[2], 'path' => $match[3] ?? '', 'query' => $match[4]];
    }

    /**
     * @return array{0: string, 1: ?int}
     */
    private static function parseAuthority(string $authority, string $location): array
    {
        if (str_contains($authority, '@')) {
            throw new SkillpackException("{$location} carries credentials, which skill addresses may not");
        }
        if (preg_match('/^\[([0-9A-Fa-f:.]+)\](?::(\d*))?$/D', $authority, $match)) {
            $host = strtolower($match[1]);
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw self::invalid($location);
            }
            $port = $match[2] ?? '';
        } elseif (preg_match('/^([A-Za-z0-9.-]+)(?::(\d*))?$/D', $authority, $match)) {
            $host = rtrim(strtolower($match[1]), '.');
            if ($host === '' || strlen($host) > 253 || str_contains($host, '..') || str_starts_with($host, '.') || str_starts_with($host, '-')) {
                throw self::invalid($location);
            }
            $port = $match[2] ?? '';
        } else {
            throw self::invalid($location);
        }
        if ($port === '') {
            return [$host, null];
        }
        $number = (int) $port;
        if (strlen($port) > 5 || $number < 1 || $number > 65535) {
            throw self::invalid($location);
        }

        return [$host, $number];
    }

    private static function path(string $path, string $location): string
    {
        if (! preg_match('/^(?:[A-Za-z0-9\-._~!$&\'()*+,;=:@\/]|%[0-9A-Fa-f]{2})*$/D', $path)) {
            throw self::invalid($location);
        }

        return $path === '' ? '/' : $path;
    }

    private static function query(?string $query, string $location): ?string
    {
        if ($query !== null && ! preg_match('/^(?:[A-Za-z0-9\-._~!$&\'()*+,;=:@\/?]|%[0-9A-Fa-f]{2})*$/D', $query)) {
            throw self::invalid($location);
        }

        return $query;
    }

    private static function removeDots(string $path): string
    {
        $output = [];
        $segments = explode('/', $path);
        $last = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            if ($segment === '.' || $segment === '..') {
                if ($segment === '..' && count($output) > 1) {
                    array_pop($output);
                }
                if ($index === $last) {
                    $output[] = '';
                }

                continue;
            }
            $output[] = $segment;
        }
        $result = implode('/', $output);

        return str_starts_with($path, '/') && ! str_starts_with($result, '/') ? '/'.$result : $result;
    }

    private static function invalid(string $location): SkillpackException
    {
        return new SkillpackException("{$location} is not an http or https address");
    }
}
