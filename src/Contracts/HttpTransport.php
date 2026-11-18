<?php

namespace Whilesmart\Skillpack\Contracts;

use Whilesmart\Skillpack\Http\HttpRequest;
use Whilesmart\Skillpack\Http\HttpResponse;

/**
 * Sends one GET request. Implementations must not follow redirects, must connect to
 * HttpRequest::$address when it is given, and must return at most maxBytes + 1 body bytes.
 */
interface HttpTransport
{
    public function send(HttpRequest $request): HttpResponse;
}
