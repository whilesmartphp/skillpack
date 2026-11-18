<?php

namespace Whilesmart\Skillpack\Sources;

use Whilesmart\Skillpack\Bundle;
use Whilesmart\Skillpack\Contracts\SkillSource;
use Whilesmart\Skillpack\Exceptions\LimitExceededException;
use Whilesmart\Skillpack\LoadOptions;
use Whilesmart\Skillpack\Pack;

/**
 * Skills from relative path => raw bytes. A root SKILL.md makes one skill holding every file;
 * otherwise each top-level folder holding SKILL.md is a skill and other files are ignored.
 */
final class FilesSource implements SkillSource
{
    private readonly LoadOptions $options;

    /**
     * @param  array<string, string>  $files
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        private readonly array $files,
        private readonly array $provenance = [],
        ?LoadOptions $options = null,
    ) {
        $this->options = $options ?? new LoadOptions;
    }

    public function load(): Pack
    {
        $files = Bundle::checked($this->files, $this->options);
        $skills = Bundle::skills($files, $this->provenance + ['type' => 'files']);
        if (count($skills) > $this->options->maxSkills) {
            throw new LimitExceededException("The bundle holds more than {$this->options->maxSkills} skills");
        }

        return new Pack($skills);
    }
}
