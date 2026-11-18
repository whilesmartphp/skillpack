<?php

namespace Whilesmart\Skillpack\Http;

final class HttpRequest
{
    /**
     * @param  string  $url  The address to request; its host is sent as the Host header and TLS name
     * @param  string|null  $address  The checked IP address to connect to instead of resolving the host again
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly int $port,
        public readonly ?string $address,
        public readonly array $headers,
        public readonly int $timeoutSeconds,
        public readonly int $maxBytes,
    ) {}
}
