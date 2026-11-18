<?php

namespace Whilesmart\Skillpack\Http;

use Whilesmart\Skillpack\Contracts\HostResolver;
use Whilesmart\Skillpack\Contracts\HttpTransport;
use Whilesmart\Skillpack\Exceptions\AccessDeniedException;
use Whilesmart\Skillpack\Exceptions\LimitExceededException;
use Whilesmart\Skillpack\Exceptions\SkillpackException;
use Whilesmart\Skillpack\LoadOptions;
use Whilesmart\Skillpack\Net\AddressPolicy;
use Whilesmart\Skillpack\Net\DnsResolver;
use Whilesmart\Skillpack\Net\Url;

/**
 * Fetches one address under LoadOptions: allowed hosts on every hop, public addresses unless
 * private ones are allowed, the resolved address pinned for the connection, redirects followed
 * by hand and the body capped.
 */
final class Fetcher
{
    private const ACCEPT = 'application/json, text/markdown, application/gzip, application/zip;q=0.9, */*;q=0.1';

    private readonly HttpTransport $transport;

    private readonly HostResolver $resolver;

    public function __construct(private readonly LoadOptions $options)
    {
        $this->transport = $options->transport ?? new CurlTransport;
        $this->resolver = $options->resolver ?? new DnsResolver;
    }

    public function fetch(Url $address): Download
    {
        if ($this->options->allowedHosts === []) {
            throw new AccessDeniedException('Remote skills need LoadOptions allowedHosts; none are configured');
        }
        if (! $this->options->allowsHost($address->host)) {
            throw new AccessDeniedException("{$address->host} is not an allowed host");
        }

        $first = $address;
        for ($hop = 0; ; $hop++) {
            $response = $this->transport->send(new HttpRequest(
                (string) $address,
                $address->host,
                $address->effectivePort(),
                $this->pin($address),
                ['Accept' => self::ACCEPT, 'User-Agent' => 'whilesmart-skillpack'],
                $this->options->timeoutSeconds,
                $this->options->maxDownloadBytes,
            ));

            if (! $response->isRedirect()) {
                break;
            }
            if ($hop >= LoadOptions::MAX_REDIRECTS) {
                throw new SkillpackException("{$first} stopped after ".LoadOptions::MAX_REDIRECTS.' redirects');
            }
            $location = $response->header('location');
            if ($location === null || trim($location) === '') {
                throw new SkillpackException("{$address} redirects without a Location");
            }
            $next = $address->resolve(trim($location));
            if ($address->scheme === 'https' && $next->scheme !== 'https') {
                throw new AccessDeniedException('HTTPS skill sources cannot redirect to HTTP');
            }
            if (! $this->options->allowsHost($next->host)) {
                throw new AccessDeniedException("{$first} redirects to {$next->host}, which is not an allowed host");
            }
            $address = $next;
        }

        if ($response->status !== 200) {
            throw new SkillpackException("{$address} answered {$response->status}");
        }
        if (strlen($response->body) > $this->options->maxDownloadBytes) {
            throw new LimitExceededException("{$address} is larger than {$this->options->maxDownloadBytes} bytes");
        }

        return new Download($response->body, $response->header('content-type') ?? '', $address);
    }

    private function pin(Url $address): string
    {
        $addresses = $this->resolver->resolve($address->host);
        if ($addresses === []) {
            throw new SkillpackException("{$address->host} could not be resolved");
        }
        foreach ($addresses as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                throw new SkillpackException("{$address->host} resolved to {$candidate}, which is not an IP address");
            }
        }
        if (! $this->options->allowPrivateAddresses) {
            foreach ($addresses as $candidate) {
                if (! AddressPolicy::isPublic($candidate)) {
                    throw new AccessDeniedException("{$address->host} resolves to {$candidate}, which is not a public address");
                }
            }
        }

        return array_values($addresses)[0];
    }
}
