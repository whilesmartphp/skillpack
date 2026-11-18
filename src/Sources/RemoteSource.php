<?php

namespace Whilesmart\Skillpack\Sources;

use JsonException;
use stdClass;
use Whilesmart\Skillpack\Archive\Extractor;
use Whilesmart\Skillpack\Bundle;
use Whilesmart\Skillpack\Contracts\SkillSource;
use Whilesmart\Skillpack\Exceptions\DigestMismatchException;
use Whilesmart\Skillpack\Exceptions\LimitExceededException;
use Whilesmart\Skillpack\Exceptions\SkillpackException;
use Whilesmart\Skillpack\Http\Download;
use Whilesmart\Skillpack\Http\Fetcher;
use Whilesmart\Skillpack\LoadOptions;
use Whilesmart\Skillpack\Net\Url;
use Whilesmart\Skillpack\Pack;
use Whilesmart\Skillpack\Skill;

/**
 * Reads skills from an http(s) address: an Agent Skills Discovery v0.2.0 index, one SKILL.md,
 * or a .zip or .tar.gz archive. A bare site address reads its well-known index. Every host,
 * including redirects and index entries, must be in LoadOptions::$allowedHosts.
 */
final class RemoteSource implements SkillSource
{
    public const DISCOVERY_SCHEMA = 'https://schemas.agentskills.io/discovery/0.2.0/schema.json';

    public const WELL_KNOWN_INDEX = '/.well-known/agent-skills/index.json';

    private const NAME_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/D';

    private const DIGEST_PATTERN = '/^sha256:[0-9a-f]{64}$/D';

    private readonly LoadOptions $options;

    public function __construct(private readonly string $location, ?LoadOptions $options = null)
    {
        $this->options = $options ?? new LoadOptions;
    }

    public function load(): Pack
    {
        $address = Url::parse($this->location);
        if ($address->path === '/' && $address->query === null) {
            $address = $address->withPath(self::WELL_KNOWN_INDEX);
        }
        $fetcher = new Fetcher($this->options);
        $got = $fetcher->fetch($address);

        return match ($kind = Detector::detect($got->contentType, $got->address->path, $got->data)) {
            Detector::INDEX => $this->index($got, $fetcher),
            Detector::SKILL_MD => new Pack([new Skill(['SKILL.md' => $got->data], [
                'type' => 'skill-md',
                'source' => $this->location,
                'root' => (string) $got->address,
                'folder' => self::markdownFolder($got->address->path),
            ])]),
            Detector::ZIP, Detector::TAR_GZIP => $this->archive($got, $kind),
            default => throw new SkillpackException(sprintf(
                '%s is not a discovery index, a SKILL.md or a .zip or .tar.gz archive (Content-Type %s)',
                $got->address,
                json_encode($got->contentType, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            )),
        };
    }

    private function archive(Download $got, string $kind): Pack
    {
        $contents = $this->extract($got, $kind);
        $dir = Bundle::topFolder($contents['files'], $contents['dirs']);
        if ($this->options->directory !== '') {
            $dir = $dir === '.' ? $this->options->directory : $dir.'/'.$this->options->directory;
        }

        $groups = Bundle::skillsIn($contents['files'], $dir, $contents['dirs']);
        if (count($groups) > $this->options->maxSkills) {
            throw new LimitExceededException("{$got->address} holds more than {$this->options->maxSkills} skills");
        }

        $skills = [];
        foreach ($groups as $folder => $files) {
            $folder = (string) $folder;
            $skills[] = new Skill($files, [
                'type' => 'archive',
                'source' => $this->location,
                'root' => $folder === '.' ? $this->location : $this->location.'!/'.$folder,
                'folder' => $folder === '.' ? self::archiveName($got->address->path) : basename($folder),
            ]);
        }

        return new Pack($skills);
    }

    private function index(Download $got, Fetcher $fetcher): Pack
    {
        try {
            $index = json_decode($got->data, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SkillpackException("{$got->address} is not a discovery index: {$exception->getMessage()}");
        }
        if (! $index instanceof stdClass) {
            throw new SkillpackException("{$got->address} is not a discovery index: it is not a JSON object");
        }
        $schema = $index->{'$schema'} ?? null;
        $skills = $index->skills ?? null;
        if ($schema !== null && ! is_string($schema)) {
            throw new SkillpackException("{$got->address} is not a discovery index: \$schema is not text");
        }
        if ($schema === null) {
            throw new SkillpackException("{$got->address} has no \$schema, so it is a v0.1.0 discovery index; skillpack reads ".self::DISCOVERY_SCHEMA.', whose digests let it verify each skill');
        }
        if ($schema !== self::DISCOVERY_SCHEMA) {
            throw new SkillpackException("{$got->address} uses discovery schema {$schema}; skillpack reads ".self::DISCOVERY_SCHEMA);
        }
        if ($skills === null) {
            throw new SkillpackException("{$got->address} has no skills list");
        }
        if (! is_array($skills)) {
            throw new SkillpackException("{$got->address} is not a discovery index: skills is not a list");
        }
        if (count($skills) > $this->options->maxSkills) {
            throw new LimitExceededException("{$got->address} lists more than {$this->options->maxSkills} skills");
        }

        $entries = [];
        foreach ($skills as $position => $entry) {
            if (! $entry instanceof stdClass) {
                throw new SkillpackException("{$got->address} is not a discovery index: skill ".($position + 1).' is not an object');
            }
            $fields = [];
            foreach (['name', 'type', 'description', 'url', 'digest'] as $field) {
                $value = $entry->{$field} ?? null;
                if ($value !== null && ! is_string($value)) {
                    throw new SkillpackException("{$got->address} is not a discovery index: skill ".($position + 1)." {$field} is not text");
                }
                $fields[$field] = $value ?? '';
            }
            $entries[] = $fields;
        }

        $loaded = [];
        $warnings = [];
        foreach ($entries as $position => $entry) {
            $label = sprintf('%s skill %d (%s)', $got->address, $position + 1, $entry['name']);
            if ($entry['type'] !== 'skill-md' && $entry['type'] !== 'archive') {
                $warnings[] = sprintf('%s: skipped type %s, which this version does not read', $label, json_encode($entry['type'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

                continue;
            }
            try {
                $loaded[] = $this->entry($got->address, $entry, $fetcher);
            } catch (SkillpackException $exception) {
                $class = $exception::class;

                throw new $class("{$label}: {$exception->getMessage()}", 0, $exception);
            }
        }

        return new Pack($loaded, $warnings);
    }

    /**
     * @param  array{name: string, type: string, description: string, url: string, digest: string}  $entry
     */
    private function entry(Url $base, array $entry, Fetcher $fetcher): Skill
    {
        if ($entry['name'] === '' || strlen($entry['name']) > 64 || ! preg_match(self::NAME_PATTERN, $entry['name'])) {
            throw new SkillpackException('name must be 1 to 64 lowercase letters, digits and single hyphens');
        }
        if (trim($entry['description']) === '' || mb_strlen($entry['description'], 'UTF-8') > 1024) {
            throw new SkillpackException('description must be 1 to 1024 characters');
        }
        if (! preg_match(self::DIGEST_PATTERN, $entry['digest'])) {
            throw new SkillpackException('digest must be sha256: followed by 64 lowercase hex characters');
        }
        if ($entry['url'] === '') {
            throw new SkillpackException('url is missing');
        }

        $address = $base->resolve($entry['url']);
        $got = $fetcher->fetch($address);
        $actual = 'sha256:'.hash('sha256', $got->data);
        if (! hash_equals($entry['digest'], $actual)) {
            throw new DigestMismatchException("{$address} has digest {$actual}, not the {$entry['digest']} the index lists");
        }

        $provenance = [
            'type' => $entry['type'],
            'source' => $this->location,
            'index' => (string) $base,
            'root' => (string) $address,
            'folder' => $entry['name'],
            'digest' => $entry['digest'],
            'index_description' => $entry['description'],
        ];

        if ($entry['type'] === 'skill-md') {
            return new Skill(['SKILL.md' => $got->data], $provenance);
        }

        $kind = Detector::detect($got->contentType, $got->address->path, $got->data);

        return new Skill($this->extract($got, $kind)['files'], $provenance);
    }

    /**
     * @return array{files: array<string, string>, dirs: array<string, true>}
     */
    private function extract(Download $got, string $kind): array
    {
        $kind = match ($kind) {
            Detector::ZIP => Extractor::ZIP,
            Detector::TAR_GZIP => Extractor::TAR_GZIP,
            default => '',
        };

        try {
            return Extractor::extract($got->data, $kind, $this->options);
        } catch (SkillpackException $exception) {
            $class = $exception::class;

            throw new $class("{$got->address}: {$exception->getMessage()}", 0, $exception);
        }
    }

    private static function markdownFolder(string $path): string
    {
        $base = basename($path);
        if (strcasecmp($base, 'SKILL.md') === 0) {
            return basename(dirname($path));
        }
        $dot = strrpos($base, '.');

        return $dot === false ? $base : substr($base, 0, $dot);
    }

    private static function archiveName(string $path): string
    {
        $name = basename($path);
        foreach (['.tar.gz', '.tgz', '.zip'] as $suffix) {
            if (str_ends_with(strtolower($name), $suffix)) {
                return substr($name, 0, -strlen($suffix));
            }
        }

        return $name;
    }
}
