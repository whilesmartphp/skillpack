<?php

namespace Whilesmart\Skillpack;

final class Problem
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly string $severity = self::ERROR,
        public readonly ?string $path = null,
    ) {}

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }

    /**
     * @return array{code: string, message: string, severity: string, path: ?string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'severity' => $this->severity,
            'path' => $this->path,
        ];
    }
}
