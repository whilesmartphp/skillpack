<?php

namespace Whilesmart\Skillpack;

use Stringable;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Whilesmart\Skillpack\Exceptions\SkillpackException;
use Whilesmart\Skillpack\Exceptions\UnsafePathException;

/**
 * One Agent Skills folder held in memory: SKILL.md frontmatter and body plus every other file
 * in the folder as raw bytes, keyed by its path relative to the folder.
 *
 * Provenance keys read by this class: "folder" (the folder name the skill name must match),
 * "root" (where the skill was read from, for messages), "digest" (a verified discovery
 * digest), "index_description" (the description a discovery index listed) and
 * "links_outside" (paths that were symbolic links leaving the folder and were not read).
 */
final class Skill
{
    public const LONGEST_BODY = 500;

    public const FIELDS = ['name', 'description', 'license', 'compatibility', 'metadata', 'allowed-tools'];

    private const NAME_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/D';

    private const LINK_PATTERN = '/\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/';

    private const PLACEHOLDER = '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/';

    private const SCHEME = '/^[a-zA-Z][a-zA-Z0-9+.-]*:/';

    /** @var array<string, string> */
    public readonly array $files;

    /** @var array<string, mixed> */
    public readonly array $provenance;

    /** @var array<string, mixed> */
    public readonly array $frontmatter;

    public readonly string $body;

    private readonly ?string $yamlError;

    /** @var array<string, true> */
    private readonly array $outside;

    /**
     * @param  array<string, string>  $files  Relative path => raw bytes. Must hold SKILL.md.
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(array $files, array $provenance = [])
    {
        $checked = [];
        foreach ($files as $path => $content) {
            $path = Paths::assertValid((string) $path);
            if (! is_string($content)) {
                throw new SkillpackException("{$path} must be given as a string of bytes");
            }
            $checked[$path] = $content;
        }
        ksort($checked, SORT_STRING);

        $outside = [];
        foreach ((array) ($provenance['links_outside'] ?? []) as $path) {
            if (is_string($path) && $path !== '') {
                $outside[$path] = true;
            }
        }

        $root = is_string($provenance['root'] ?? null) ? $provenance['root'] : 'skill';
        if (! isset($checked['SKILL.md'])) {
            throw new SkillpackException("{$root} has no SKILL.md");
        }

        try {
            [$frontmatter, $body, $yamlError] = Frontmatter::split($checked['SKILL.md']);
        } catch (SkillpackException $exception) {
            throw new SkillpackException("{$root}: {$exception->getMessage()}", 0, $exception);
        }

        $this->files = $checked;
        $this->provenance = $provenance;
        $this->frontmatter = $frontmatter;
        $this->body = $body;
        $this->yamlError = $yamlError;
        $this->outside = $outside;
    }

    public function name(): string
    {
        return $this->text('name');
    }

    public function description(): string
    {
        return $this->text('description');
    }

    public function folder(): string
    {
        $folder = $this->provenance['folder'] ?? null;

        return is_string($folder) && $folder !== '' ? $folder : $this->name();
    }

    public function root(): string
    {
        $root = $this->provenance['root'] ?? null;

        return is_string($root) && $root !== '' ? $root : $this->folder();
    }

    public function digest(): ?string
    {
        $digest = $this->provenance['digest'] ?? null;

        return is_string($digest) && $digest !== '' ? $digest : null;
    }

    public function yamlError(): ?string
    {
        return $this->yamlError;
    }

    /**
     * @return array<string, string>
     */
    public function metadata(): array
    {
        $found = [];
        $metadata = $this->frontmatter['metadata'] ?? null;
        if (is_array($metadata)) {
            foreach ($metadata as $key => $value) {
                $found[(string) $key] = self::format($value);
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    public function allowedTools(): array
    {
        $value = $this->frontmatter['allowed-tools'] ?? null;

        return is_string($value) ? (preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: []) : [];
    }

    /**
     * @return array{id: string, version: string}|null
     */
    public function rubric(): ?array
    {
        $metadata = $this->metadata();
        if (($metadata['rubric-id'] ?? '') === '') {
            return null;
        }

        return ['id' => $metadata['rubric-id'], 'version' => $metadata['rubric-version'] ?? ''];
    }

    /**
     * Files under one of the skill's folders, relative to it, sorted.
     *
     * @return list<string>
     */
    public function filesIn(string $folder): array
    {
        $prefix = trim($folder, '/').'/';
        $found = [];
        foreach (array_keys($this->files) as $path) {
            if (str_starts_with($path, $prefix) && ! Paths::isSkipped(substr($path, strlen($prefix)))) {
                $found[] = substr($path, strlen($prefix));
            }
        }
        sort($found, SORT_STRING);

        return $found;
    }

    /**
     * @return list<string>
     */
    public function references(): array
    {
        return $this->filesIn('references');
    }

    /**
     * @return list<string>
     */
    public function assets(): array
    {
        return $this->filesIn('assets');
    }

    public function reference(string $name): string
    {
        return $this->read('references', $name);
    }

    public function asset(string $name): string
    {
        return $this->read('assets', $name);
    }

    /**
     * Fills {{value}} placeholders in an assets/ template. Every placeholder needs a value.
     *
     * @param  array<string, scalar|Stringable|null>  $values
     */
    public function render(string $name, array $values = []): string
    {
        $template = $this->asset($name);
        preg_match_all(self::PLACEHOLDER, $template, $matches);
        $missing = [];
        foreach ($matches[1] as $key) {
            if (! array_key_exists($key, $values)) {
                $missing[$key] = $key;
            }
        }
        if ($missing !== []) {
            sort($missing, SORT_STRING);
            throw new SkillpackException("{$name} needs values for: ".implode(', ', $missing));
        }

        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            fn (array $match) => self::format($values[$match[1]]),
            $template,
        );
    }

    /**
     * Local files SKILL.md links to, relative to the skill folder.
     *
     * @return list<string>
     */
    public function links(): array
    {
        preg_match_all(self::LINK_PATTERN, $this->body, $matches);
        $found = [];
        foreach ($matches[1] as $target) {
            $target = explode('#', $target, 2)[0];
            if ($target === '' || preg_match(self::SCHEME, $target) || isset($found[$target])) {
                continue;
            }
            $found[$target] = $target;
        }

        return array_values($found);
    }

    /**
     * Checks declared in checks.yaml, in file order. Running them is up to the host.
     *
     * @return list<array{id: string, description: string}>
     */
    public function checks(): array
    {
        if (isset($this->outside['checks.yaml']) || ! isset($this->files['checks.yaml'])) {
            return [];
        }

        try {
            $document = Yaml::parse($this->files['checks.yaml']);
        } catch (ParseException) {
            return [];
        }
        if (! is_array($document) || ! array_key_exists('checks', $document) || $document['checks'] === null) {
            return [];
        }
        if (! is_array($document['checks']) || ! array_is_list($document['checks'])) {
            return [];
        }

        $checks = [];
        foreach ($document['checks'] as $entry) {
            if ($entry === null) {
                $checks[] = ['id' => '', 'description' => ''];

                continue;
            }
            if (! is_array($entry) || ($entry !== [] && array_is_list($entry))) {
                return [];
            }
            $id = $entry['id'] ?? null;
            $description = $entry['description'] ?? null;
            if (! self::isScalar($id) || ! self::isScalar($description)) {
                return [];
            }
            $checks[] = ['id' => self::format($id), 'description' => self::format($description)];
        }

        return $checks;
    }

    /**
     * Reports problems against the Agent Skills specification. Errors make the skill invalid.
     *
     * @return list<Problem>
     */
    public function validate(): array
    {
        $problems = [];
        $add = function (string $code, string $message, string $severity = Problem::ERROR, ?string $path = null) use (&$problems): void {
            $problems[] = new Problem($code, $message, $severity, $path);
        };

        if ($this->yamlError !== null) {
            $add('frontmatter-yaml', "Frontmatter is not valid YAML, so strict readers will reject the skill; quote values that contain ': ' ({$this->yamlError})");
        }

        $extra = array_values(array_diff(array_map('strval', array_keys($this->frontmatter)), self::FIELDS));
        if ($extra !== []) {
            sort($extra, SORT_STRING);
            $add('unknown-field', 'Frontmatter fields not in the spec: '.implode(', ', $extra));
        }

        $name = $this->frontmatter['name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            $add('name-missing', 'Frontmatter needs a name');
        } else {
            $name = trim($name);
            if (($length = mb_strlen($name, 'UTF-8')) > 64) {
                $add('name-length', "Name is {$length} characters; the limit is 64");
            }
            if (! preg_match(self::NAME_PATTERN, $name)) {
                $add('name-format', sprintf('Name %s must be lowercase letters, digits and single hyphens, not starting or ending with a hyphen', self::quote($name)));
            }
            if ($name !== $this->folder()) {
                $add('name-folder', sprintf('Name %s must match its folder %s', self::quote($name), self::quote($this->folder())));
            }
        }

        $description = $this->frontmatter['description'] ?? null;
        if (! is_string($description) || trim($description) === '') {
            $add('description-missing', 'Frontmatter needs a description');
        } else {
            if (($length = mb_strlen(trim($description), 'UTF-8')) > 1024) {
                $add('description-length', "Description is {$length} characters; the limit is 1024");
            }
            if (strpbrk($description, '<>') !== false) {
                $add('description-brackets', 'Description cannot contain < or >');
            }
        }

        if (($compatibility = $this->frontmatter['compatibility'] ?? null) !== null) {
            if (! is_string($compatibility)) {
                $add('compatibility-type', 'Compatibility must be text');
            } elseif (($length = mb_strlen($compatibility, 'UTF-8')) > 500) {
                $add('compatibility-length', "Compatibility is {$length} characters; the limit is 500");
            }
        }

        if (($metadata = $this->frontmatter['metadata'] ?? null) !== null) {
            $ok = is_array($metadata) && ($metadata === [] || ! array_is_list($metadata));
            if ($ok) {
                foreach ($metadata as $key => $value) {
                    if (! is_string($key) || ! is_string($value)) {
                        $ok = false;
                    }
                }
            }
            if (! $ok) {
                $add('metadata-type', 'Metadata must map text keys to text values');
            }
        }

        if (($tools = $this->frontmatter['allowed-tools'] ?? null) !== null && ! is_string($tools)) {
            $add('allowed-tools-type', 'allowed-tools must be a space-separated string');
        }

        if (($nested = $this->nestedSkills()) !== []) {
            $add('nested-skill', 'A skill holds one SKILL.md; also found: '.implode(', ', $nested));
        }

        foreach ($this->links() as $target) {
            $resolved = $this->resolve($target);
            if ($resolved === null) {
                $add('link-outside', "{$target} points outside the skill folder", Problem::ERROR, $target);

                continue;
            }
            if (! $this->exists($resolved)) {
                $add('link-missing', "SKILL.md links to {$target}, which does not exist", Problem::ERROR, $target);
            }
        }

        $indexDescription = $this->provenance['index_description'] ?? null;
        if (is_string($indexDescription) && $indexDescription !== '' && trim($indexDescription) !== $this->description()) {
            $add('index-description', 'The discovery index describes this skill differently from its SKILL.md', Problem::WARNING);
        }

        if (($lines = substr_count($this->body, "\n") + 1) > self::LONGEST_BODY) {
            $add('body-length', sprintf('SKILL.md body is %d lines; keep it under %d and move detail into references/', $lines, self::LONGEST_BODY), Problem::WARNING);
        }

        return $problems;
    }

    public function valid(): bool
    {
        foreach ($this->validate() as $problem) {
            if ($problem->isError()) {
                return false;
            }
        }

        return true;
    }

    /**
     * A sha256 digest over every file path and its bytes, independent of provenance.
     */
    public function contentHash(): string
    {
        $context = hash_init('sha256');
        foreach ($this->files as $path => $content) {
            hash_update($context, strlen($path).':'.$path."\n".strlen($content).':');
            hash_update($context, $content);
            hash_update($context, "\n");
        }

        return 'sha256:'.hash_final($context);
    }

    private function text(string $key): string
    {
        $value = $this->frontmatter[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    private function read(string $folder, string $name): string
    {
        $name = str_replace('\\', '/', $name);
        foreach (explode('/', $name) as $part) {
            if ($part === '..') {
                throw new UnsafePathException("{$name} is outside the skill folder");
            }
        }
        foreach ([$name, $name.'.md'] as $candidate) {
            $target = $this->resolve($folder.'/'.$candidate);
            if ($target === null) {
                throw new UnsafePathException("{$name} is outside the skill folder");
            }
            if (isset($this->files[$target])) {
                return $this->files[$target];
            }
        }
        $known = implode(', ', $this->filesIn($folder));

        throw new SkillpackException(sprintf('%s has no %s/%s (has: %s)', $this->name(), $folder, $name, $known === '' ? 'none' : $known));
    }

    private function resolve(string $relative): ?string
    {
        $relative = str_replace('\\', '/', $relative);
        if (str_starts_with($relative, '/') || str_contains($relative, "\0") || preg_match('/^[A-Za-z]:/', $relative)) {
            return null;
        }
        $cleaned = Paths::clean($relative);
        if (Paths::escapes($cleaned)) {
            return null;
        }
        foreach ($this->outside as $path => $_) {
            if ($cleaned === $path || str_starts_with($cleaned, $path.'/')) {
                return null;
            }
        }

        return $cleaned;
    }

    private function exists(string $path): bool
    {
        if ($path === '.' || isset($this->files[$path])) {
            return true;
        }
        foreach (array_keys($this->files) as $file) {
            if (str_starts_with($file, $path.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function nestedSkills(): array
    {
        $found = [];
        foreach (array_keys($this->files) as $path) {
            if ($path === 'SKILL.md' || basename($path) !== 'SKILL.md' || Paths::isSkipped($path)) {
                continue;
            }
            if (in_array(explode('/', $path, 2)[0], Paths::SKIPPED_AT_ROOT, true)) {
                continue;
            }
            $found[] = $path;
        }
        sort($found, SORT_STRING);

        return $found;
    }

    private static function isScalar(mixed $value): bool
    {
        return $value === null || is_scalar($value);
    }

    private static function format(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        };
    }

    private static function quote(string $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
