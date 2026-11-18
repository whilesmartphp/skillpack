<?php

namespace Whilesmart\Skillpack;

use Closure;
use JsonException;
use stdClass;
use Whilesmart\Skillpack\Exceptions\ToolArgumentException;

/**
 * A function an agent loop registers: a name, a description, a JSON Schema for its arguments
 * and the code that answers a call. Every argument is text and undeclared arguments are refused.
 */
final class Tool
{
    /**
     * @param  array<string, mixed>  $parameters  JSON Schema; an empty "properties" is an stdClass so it encodes as {}
     * @param  list<string>  $required
     * @param  list<string>  $allowed
     * @param  Closure(array<string, string>): string  $handler
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters,
        private readonly array $required,
        private readonly array $allowed,
        private readonly Closure $handler,
    ) {}

    public function parametersJson(): string
    {
        return (string) json_encode($this->parameters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{name: string, description: string, parameters: array<string, mixed>}
     */
    public function schema(): array
    {
        return ['name' => $this->name, 'description' => $this->description, 'parameters' => $this->parameters];
    }

    /**
     * Answers a model's call. Arguments may be the model's raw JSON, a decoded array or null.
     *
     * @param  string|array<mixed>|stdClass|null  $arguments
     */
    public function call(string|array|stdClass|null $arguments = null): string
    {
        $values = [];
        foreach ($this->decode($arguments) as $key => $value) {
            $key = (string) $key;
            if (! in_array($key, $this->allowed, true)) {
                throw new ToolArgumentException("{$this->name} does not take {$key}");
            }
            if (! is_string($value)) {
                throw new ToolArgumentException("{$this->name} needs {$key} as text");
            }
            $values[$key] = $value;
        }
        foreach ($this->required as $key) {
            if (! array_key_exists($key, $values)) {
                throw new ToolArgumentException("{$this->name} needs {$key}");
            }
        }

        return ($this->handler)($values);
    }

    /**
     * @param  string|array<mixed>|stdClass|null  $arguments
     * @return array<mixed>
     */
    private function decode(string|array|stdClass|null $arguments): array
    {
        if ($arguments === null) {
            return [];
        }
        if ($arguments instanceof stdClass) {
            return get_object_vars($arguments);
        }
        if (is_array($arguments)) {
            if ($arguments !== [] && array_is_list($arguments)) {
                throw new ToolArgumentException("{$this->name} arguments must be a JSON object");
            }

            return $arguments;
        }
        $trimmed = trim($arguments);
        if ($trimmed === '') {
            throw new ToolArgumentException("{$this->name} arguments must be a JSON object");
        }
        try {
            $decoded = json_decode($trimmed, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ToolArgumentException("{$this->name} arguments must be a JSON object");
        }
        if (! $decoded instanceof stdClass) {
            throw new ToolArgumentException("{$this->name} arguments must be a JSON object");
        }

        return get_object_vars($decoded);
    }
}
