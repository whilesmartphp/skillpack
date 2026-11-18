<?php

namespace Whilesmart\Skillpack\Http;

final class HttpResponse
{
    /** @var array<string, string> */
    public readonly array $headers;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        array $headers,
        public readonly string $body,
    ) {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = (string) $value;
        }
        $this->headers = $normalized;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isRedirect(): bool
    {
        return in_array($this->status, [301, 302, 303, 307, 308], true);
    }
}
