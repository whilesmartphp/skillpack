<?php

namespace Whilesmart\Skillpack\Net;

use Whilesmart\Skillpack\Contracts\HostResolver;

final class DnsResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];
        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $address) {
                $addresses[$address] = $address;
            }
        }

        if (function_exists('dns_get_record')) {
            set_error_handler(fn () => true);
            try {
                $records = dns_get_record($host, DNS_AAAA);
            } finally {
                restore_error_handler();
            }
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                        $addresses[$record['ipv6']] = $record['ipv6'];
                    }
                }
            }
        }

        return array_values($addresses);
    }
}
