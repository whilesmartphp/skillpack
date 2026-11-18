<?php

namespace Whilesmart\Skillpack\Net;

/**
 * Tells public internet addresses from loopback, private, link-local, shared, multicast and
 * reserved ones, including IPv4 addresses carried inside IPv6 forms.
 */
final class AddressPolicy
{
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8],
        ['10.0.0.0', 8],
        ['100.64.0.0', 10],
        ['127.0.0.0', 8],
        ['169.254.0.0', 16],
        ['172.16.0.0', 12],
        ['192.0.0.0', 24],
        ['192.168.0.0', 16],
        ['198.18.0.0', 15],
        ['224.0.0.0', 4],
        ['240.0.0.0', 4],
    ];

    private const BLOCKED_V6 = [
        ['::', 128],
        ['::1', 128],
        ['fc00::', 7],
        ['fe80::', 10],
        ['fec0::', 10],
        ['ff00::', 8],
        ['100::', 64],
        ['2001:db8::', 32],
    ];

    private const EMBEDDED_V4 = [
        ['::ffff:0:0', 96, 12],
        ['::', 96, 12],
        ['64:ff9b::', 96, 12],
        ['64:ff9b:1::', 48, 12],
        ['2002::', 16, 2],
    ];

    public static function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $packed = inet_pton($address);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            return ! self::matchesAny($packed, self::BLOCKED_V4);
        }
        foreach (self::BLOCKED_V6 as [$network, $bits]) {
            if (self::matches($packed, (string) inet_pton($network), $bits)) {
                return false;
            }
        }
        foreach (self::EMBEDDED_V4 as [$network, $bits, $offset]) {
            if (self::matches($packed, (string) inet_pton($network), $bits)) {
                return ! self::matchesAny(substr($packed, $offset, 4), self::BLOCKED_V4);
            }
        }

        return true;
    }

    /**
     * @param  list<array{0: string, 1: int}>  $networks
     */
    private static function matchesAny(string $packed, array $networks): bool
    {
        foreach ($networks as [$network, $bits]) {
            if (self::matches($packed, (string) inet_pton($network), $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $address, string $network, int $bits): bool
    {
        if (strlen($address) !== strlen($network)) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
