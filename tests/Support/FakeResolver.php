<?php

namespace Whilesmart\Skillpack\Tests\Support;

use Whilesmart\Skillpack\Contracts\HostResolver;

final class FakeResolver implements HostResolver
{
    /**
     * @param  array<string, list<string>>  $hosts
     */
    public function __construct(private readonly array $hosts = [], private readonly string $default = '203.0.113.10') {}

    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return $this->hosts[$host] ?? [$this->default];
    }
}
