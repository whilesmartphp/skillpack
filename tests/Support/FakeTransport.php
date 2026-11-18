<?php

namespace Whilesmart\Skillpack\Tests\Support;

use Whilesmart\Skillpack\Contracts\HttpTransport;
use Whilesmart\Skillpack\Http\HttpRequest;
use Whilesmart\Skillpack\Http\HttpResponse;

/**
 * Serves locally built bodies by exact URL, like a test HTTP server, and records each request.
 */
final class FakeTransport implements HttpTransport
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    /**
     * @param  array<string, array{0: string, 1: string}|array{redirect: string}>  $routes  URL => [content type, body] or a redirect
     */
    public function __construct(private array $routes = []) {}

    public function serve(string $url, string $contentType, string $body): self
    {
        $this->routes[$url] = [$contentType, $body];

        return $this;
    }

    public function redirect(string $url, string $location): self
    {
        $this->routes[$url] = ['redirect' => $location];

        return $this;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $route = $this->routes[$request->url] ?? null;
        if ($route === null) {
            return new HttpResponse(404, ['Content-Type' => 'text/plain'], '404 page not found');
        }
        if (isset($route['redirect'])) {
            return new HttpResponse(302, ['Location' => $route['redirect']], '');
        }
        [$contentType, $body] = $route;
        $headers = $contentType === '' ? [] : ['Content-Type' => $contentType];

        return new HttpResponse(200, $headers, substr($body, 0, $request->maxBytes + 1));
    }
}
