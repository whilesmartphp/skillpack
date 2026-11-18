<?php

namespace Whilesmart\Skillpack\Http;

use Whilesmart\Skillpack\Net\Url;

final class Download
{
    public function __construct(
        public readonly string $data,
        public readonly string $contentType,
        public readonly Url $address,
    ) {}
}
