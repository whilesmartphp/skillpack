<?php

namespace Whilesmart\Skillpack\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Whilesmart\Skillpack\Exceptions\SkillpackException;
use Whilesmart\Skillpack\Net\AddressPolicy;
use Whilesmart\Skillpack\Net\Url;
use Whilesmart\Skillpack\Tests\TestCase;

class NetTest extends TestCase
{
    #[Test]
    public function urls_are_parsed_and_rebuilt_from_checked_parts(): void
    {
        $url = Url::parse('HTTPS://Skills.Test:8443/a/./b/../c?x=1#frag');

        $this->assertSame('https', $url->scheme);
        $this->assertSame('skills.test', $url->host);
        $this->assertSame(8443, $url->effectivePort());
        $this->assertSame('https://skills.test:8443/a/c?x=1', (string) $url);
        $this->assertSame('http://[::1]/', (string) Url::parse('http://[::1]'));
        $this->assertSame(443, Url::parse('https://skills.test')->effectivePort());
    }

    #[Test]
    public function references_resolve_against_the_base_address(): void
    {
        $base = Url::parse('https://skills.test/.well-known/agent-skills/index.json');

        $this->assertSame('https://skills.test/.well-known/agent-skills/code-review/SKILL.md', (string) $base->resolve('code-review/SKILL.md'));
        $this->assertSame('https://skills.test/cdn/a.tar.gz', (string) $base->resolve('/cdn/a.tar.gz'));
        $this->assertSame('https://skills.test/up.md', (string) $base->resolve('../../up.md'));
        $this->assertSame('https://skills.test/up.md', (string) $base->resolve('../../../../up.md'));
        $this->assertSame('https://cdn.test/x', (string) $base->resolve('//cdn.test/x'));
        $this->assertSame('http://other.test/y', (string) $base->resolve('http://other.test/y'));
        $this->assertSame('https://skills.test/.well-known/agent-skills/index.json?v=2', (string) $base->resolve('?v=2'));
    }

    #[Test]
    public function unsafe_or_foreign_addresses_are_refused(): void
    {
        foreach ([
            'file:///etc/passwd',
            'ftp://skills.test/a',
            'https://',
            'https://skills.test/a b',
            "https://skills.test/\r\nHost: evil",
            'https://skills.test:99999/',
            'https://user:pw@skills.test/',
            'https://ski\\lls.test/',
            'https://[not-ip]/',
            'javascript:alert(1)',
        ] as $location) {
            try {
                Url::parse($location);
                $this->fail("accepted {$location}");
            } catch (SkillpackException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function only_public_addresses_pass_the_policy(): void
    {
        foreach (['203.0.113.10', '8.8.8.8', '2606:4700::1111', '172.32.0.1'] as $address) {
            $this->assertTrue(AddressPolicy::isPublic($address), $address);
        }
        foreach ([
            '127.0.0.1', '10.1.2.3', '172.16.5.4', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0',
            '224.0.0.1', '255.255.255.255', '::1', '::', 'fe80::1', 'fd00::1', '::ffff:127.0.0.1',
            '::ffff:10.0.0.1', '64:ff9b::a00:1', '2002:7f00:1::', 'ff02::1', 'not-an-ip',
        ] as $address) {
            $this->assertFalse(AddressPolicy::isPublic($address), $address);
        }
    }
}
