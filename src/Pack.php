<?php

namespace Whilesmart\Skillpack;

use Countable;
use stdClass;
use Whilesmart\Skillpack\Exceptions\SkillNotFoundException;
use Whilesmart\Skillpack\Exceptions\SkillpackException;

/**
 * Skills by name, in the order they were loaded.
 */
final class Pack implements Countable
{
    /** @var list<Skill> */
    private readonly array $skills;

    /** @var array<string, Skill> */
    private readonly array $byName;

    /** @var list<string> */
    private readonly array $warnings;

    /**
     * @param  array<Skill>  $skills
     * @param  array<string>  $warnings  What loading skipped without failing
     */
    public function __construct(array $skills, array $warnings = [])
    {
        $ordered = [];
        $byName = [];
        foreach ($skills as $skill) {
            if (! $skill instanceof Skill) {
                throw new SkillpackException('A pack holds only Skill instances');
            }
            $name = $skill->name();
            if (isset($byName[$name])) {
                throw new SkillpackException("Two skills are named {$name}: {$byName[$name]->root()} and {$skill->root()}");
            }
            $byName[$name] = $skill;
            $ordered[] = $skill;
        }
        $this->skills = $ordered;
        $this->byName = $byName;
        $this->warnings = array_values(array_map('strval', $warnings));
    }

    /**
     * @return list<Skill>
     */
    public function all(): array
    {
        return $this->skills;
    }

    public function count(): int
    {
        return count($this->skills);
    }

    public function has(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    public function get(string $name): Skill
    {
        if (isset($this->byName[$name])) {
            return $this->byName[$name];
        }
        $known = implode(', ', $this->names());

        throw new SkillNotFoundException("No skill named {$name} (have: ".($known === '' ? 'none' : $known).')');
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return list<array{name: string, description: string}>
     */
    public function index(): array
    {
        return array_map(
            fn (Skill $skill) => ['name' => $skill->name(), 'description' => $skill->description()],
            $this->skills,
        );
    }

    public function indexText(): string
    {
        return implode("\n", array_map(
            fn (array $entry) => '- '.$entry['name'].': '.$entry['description'],
            $this->index(),
        ));
    }

    /**
     * Validation problems by skill name, leaving out skills with none.
     *
     * @return array<string, list<Problem>>
     */
    public function problems(): array
    {
        $found = [];
        foreach ($this->skills as $skill) {
            if (($problems = $skill->validate()) !== []) {
                $found[$skill->name()] = $problems;
            }
        }

        return $found;
    }

    /**
     * list_skills, read_skill and read_reference for a model that reads skills on demand.
     * Nothing a skill holds is executed.
     *
     * @return list<Tool>
     */
    public function tools(): array
    {
        $names = $this->names();
        sort($names, SORT_STRING);

        return [
            new Tool(
                'list_skills',
                'List the skills you can draw on, each with when it applies.',
                self::schema(new stdClass, []),
                [],
                [],
                fn (array $arguments): string => $this->indexText(),
            ),
            new Tool(
                'read_skill',
                "Read a skill's guidance and the references it offers.",
                self::schema(['name' => ['type' => 'string', 'enum' => $names]], ['name']),
                ['name'],
                ['name'],
                function (array $arguments): string {
                    $skill = $this->get($arguments['name']);
                    $parts = [trim($skill->body)];
                    if (($references = $skill->references()) !== []) {
                        $parts[] = 'References: '.implode(', ', $references);
                    }

                    return implode("\n\n", $parts);
                },
            ),
            new Tool(
                'read_reference',
                'Read one reference file from a skill, by the name read_skill lists.',
                self::schema([
                    'skill' => ['type' => 'string', 'enum' => $names],
                    'name' => ['type' => 'string'],
                ], ['skill', 'name']),
                ['skill', 'name'],
                ['skill', 'name'],
                fn (array $arguments): string => $this->get($arguments['skill'])->reference($arguments['name']),
            ),
        ];
    }

    public function tool(string $name): Tool
    {
        foreach ($this->tools() as $tool) {
            if ($tool->name === $name) {
                return $tool;
            }
        }

        throw new SkillpackException("No tool named {$name}");
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return array_map(fn (Skill $skill) => $skill->name(), $this->skills);
    }

    /**
     * @param  array<string, mixed>|stdClass  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private static function schema(array|stdClass $properties, array $required): array
    {
        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        $schema['additionalProperties'] = false;

        return $schema;
    }
}
