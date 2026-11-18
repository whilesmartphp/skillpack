<?php

namespace Whilesmart\Skillpack\Contracts;

interface HostResolver
{
    /**
     * @return list<string> IPv4 or IPv6 addresses for the host name
     */
    public function resolve(string $host): array;
}
