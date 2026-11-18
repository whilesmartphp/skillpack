<?php

namespace Whilesmart\Skillpack\Http;

use CurlHandle;
use Whilesmart\Skillpack\Contracts\HttpTransport;
use Whilesmart\Skillpack\Exceptions\SkillpackException;

/**
 * Sends one GET with ext-curl: no redirects, no proxy, http and https only, connecting to the
 * checked address and stopping after maxBytes + 1 body bytes.
 */
final class CurlTransport implements HttpTransport
{
    public function send(HttpRequest $request): HttpResponse
    {
        if (! function_exists('curl_init')) {
            throw new SkillpackException('Remote skills need ext-curl or a custom HttpTransport in LoadOptions');
        }

        $body = '';
        $headers = [];
        $truncated = false;
        $limit = $request->maxBytes + 1;

        $handle = curl_init();
        if (! $handle instanceof CurlHandle) {
            throw new SkillpackException('Could not start an HTTP request');
        }

        $lines = [];
        foreach ($request->headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_CONNECTTIMEOUT => $request->timeoutSeconds,
            CURLOPT_TIMEOUT => $request->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$headers): int {
                if (preg_match('/^HTTP\/\S+\s+\d{3}/', $line)) {
                    $headers = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$truncated, $limit): int {
                $room = $limit - strlen($body);
                if (strlen($chunk) >= $room) {
                    $body .= substr($chunk, 0, max(0, $room));
                    $truncated = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ];
        if ($request->address !== null) {
            $address = str_contains($request->address, ':') ? '['.$request->address.']' : $request->address;
            $options[CURLOPT_RESOLVE] = [$request->host.':'.$request->port.':'.$address];
        }
        curl_setopt_array($handle, $options);

        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        unset($handle);

        if ($ok === false && ! $truncated) {
            throw new SkillpackException("{$request->url} could not be fetched: {$error}");
        }

        return new HttpResponse($status, $headers, $body);
    }
}
