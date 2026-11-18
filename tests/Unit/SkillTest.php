<?php

namespace Whilesmart\Skillpack\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Whilesmart\Skillpack\Exceptions\SkillpackException;
use Whilesmart\Skillpack\Exceptions\UnsafePathException;
use Whilesmart\Skillpack\Frontmatter;
use Whilesmart\Skillpack\Loader;
use Whilesmart\Skillpack\Problem;
use Whilesmart\Skillpack\Skill;
use Whilesmart\Skillpack\Tests\TestCase;

class SkillTest extends TestCase
{
    private function layout(): Skill
    {
        return Loader::load(self::FIXTURES.'/skills/page-layout')->get('page-layout');
    }

    #[Test]
    public function metadata_rubric_checks_and_links_are_read(): void
    {
        $skill = $this->layout();

        $this->assertSame(['id' => 'layout-review', 'version' => '2'], $skill->rubric());
        $this->assertSame([
            ['id' => 'primary-action', 'description' => "The page's main action is visible without scrolling"],
            ['id' => 'tap-targets', 'description' => ''],
        ], $skill->checks());
        $this->assertSame(['references/grids.md'], $skill->links());
        $this->assertSame(['rubric-id' => 'layout-review', 'rubric-version' => '2'], $skill->metadata());
    }

    #[Test]
    public function references_and_templates_are_read_from_inside_the_skill(): void
    {
        $skill = $this->layout();

        $this->assertSame(['grids.md'], $skill->references());
        $this->assertStringStartsWith('# Grids', $skill->reference('grids'));
        $this->assertSame($skill->reference('grids'), $skill->reference('grids.md'));

        try {
            $skill->reference('../SKILL.md');
            $this->fail('read a file outside the references folder');
        } catch (UnsafePathException $exception) {
            $this->assertStringContainsString('outside the skill folder', $exception->getMessage());
        }

        try {
            $skill->reference('missing');
            $this->fail('read a missing reference');
        } catch (SkillpackException $exception) {
            $this->assertStringContainsString('(has: grids.md)', $exception->getMessage());
        }

        try {
            $skill->render('section', ['title' => 'Home']);
            $this->fail('rendered without every value');
        } catch (SkillpackException $exception) {
            $this->assertSame('section needs values for: count', $exception->getMessage());
        }

        $this->assertSame("Section for Home with 3 cards.\n", $skill->render('section', ['title' => 'Home', 'count' => 3]));
    }

    #[Test]
    public function validation_reports_each_spec_problem(): void
    {
        $skill = Loader::load(self::FIXTURES.'/broken/bad-skill')->get('Bad_Skill');

        $this->assertSame([
            'allowed-tools-type', 'compatibility-type', 'description-brackets', 'link-missing', 'link-outside',
            'metadata-type', 'name-folder', 'name-format', 'unknown-field',
        ], $this->codes($skill->validate()));
        $this->assertFalse($skill->valid());

        $paths = [];
        foreach ($skill->validate() as $problem) {
            if ($problem->path !== null) {
                $paths[$problem->code] = $problem->path;
            }
        }
        $this->assertSame(['link-missing' => 'references/missing.md', 'link-outside' => '../../secret.md'], $paths);
    }

    #[Test]
    public function nested_skills_ignore_evals_and_dependencies(): void
    {
        $skill = Loader::load(self::FIXTURES.'/broken/nested')->get('nested');
        $problems = $skill->validate();

        $this->assertCount(1, $problems);
        $this->assertSame('nested-skill', $problems[0]->code);
        $this->assertSame('A skill holds one SKILL.md; also found: inner/SKILL.md', $problems[0]->message);

        $bundled = Loader::fromFiles([
            'SKILL.md' => "---\nname: nested\ndescription: Holds another skill inside it.\n---\nBody.\n",
            'node_modules/pkg/SKILL.md' => "---\nname: pkg\ndescription: A dependency.\n---\n",
            'evals/case/SKILL.md' => "---\nname: case\ndescription: An eval.\n---\n",
        ])->get('nested');
        $this->assertSame([], $bundled->validate());
        $this->assertArrayNotHasKey('node_modules/pkg/SKILL.md', $bundled->files);
    }

    #[Test]
    public function plain_frontmatter_loads_but_is_an_error(): void
    {
        $skill = Loader::load(self::FIXTURES.'/broken/plain-yaml')->get('plain-yaml');

        $this->assertSame('Use when: the colon breaks YAML', $skill->description());
        $this->assertSame(['frontmatter-yaml'], $this->codes($skill->validate()));
        $this->assertNotNull($skill->yamlError());

        try {
            Frontmatter::split("---\nname: a: b\n---\n", false);
            $this->fail('strict split accepted invalid YAML');
        } catch (SkillpackException) {
        }

        $this->expectException(SkillpackException::class);
        Frontmatter::split('no frontmatter');
    }

    #[Test]
    public function frontmatter_must_be_a_mapping(): void
    {
        $this->expectExceptionMessage('Frontmatter must be a mapping');
        Frontmatter::split("---\n- a\n- b\n---\nBody\n");
    }

    #[Test]
    public function long_bodies_are_a_warning(): void
    {
        $skill = new Skill([
            'SKILL.md' => "---\nname: long-skill\ndescription: Long.\n---\n".str_repeat("line\n", Skill::LONGEST_BODY + 1),
        ], ['folder' => 'long-skill']);
        $problems = $skill->validate();

        $this->assertCount(1, $problems);
        $this->assertSame('body-length', $problems[0]->code);
        $this->assertSame(Problem::WARNING, $problems[0]->severity);
        $this->assertTrue($skill->valid());
    }

    #[Test]
    public function length_and_type_limits_are_enforced(): void
    {
        $skill = new Skill(['SKILL.md' => "---\nname: ".str_repeat('a', 65)."\ndescription: ".str_repeat('d', 1025)."\ncompatibility: ".str_repeat('c', 501)."\nmetadata: [a]\n---\n"]);

        $this->assertSame(
            ['compatibility-length', 'description-length', 'metadata-type', 'name-length'],
            $this->codes($skill->validate()),
        );

        $missing = new Skill(['SKILL.md' => "---\nlicense: MIT\n---\n"]);
        $this->assertSame(['description-missing', 'name-missing'], $this->codes($missing->validate()));
    }

    #[Test]
    public function the_index_description_is_compared_with_the_skill(): void
    {
        $skill = new Skill(['SKILL.md' => self::REVIEW_SKILL], ['index_description' => 'Something else.']);

        $this->assertSame(['index-description'], $this->codes($skill->validate()));
        $this->assertTrue($skill->valid());
    }

    #[Test]
    public function problems_serialize_to_arrays(): void
    {
        $problem = new Problem('link-missing', 'Missing', Problem::ERROR, 'references/a.md');

        $this->assertSame(
            ['code' => 'link-missing', 'message' => 'Missing', 'severity' => 'error', 'path' => 'references/a.md'],
            $problem->toArray(),
        );
        $this->assertNull((new Problem('x', 'y'))->toArray()['path']);
    }

    #[Test]
    public function the_content_hash_covers_paths_and_bytes_only(): void
    {
        $files = ['SKILL.md' => self::LAYOUT_SKILL, 'references/grids.md' => "# Grids\n"];
        $first = new Skill($files, ['root' => 'one']);
        $reordered = new Skill(array_reverse($files, true), ['root' => 'two']);
        $changed = new Skill(['SKILL.md' => self::LAYOUT_SKILL, 'references/grids.md' => "# Grid\n"]);
        $moved = new Skill(['SKILL.md' => self::LAYOUT_SKILL, 'references/grid.md' => "# Grids\n"]);

        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $first->contentHash());
        $this->assertSame($first->contentHash(), $reordered->contentHash());
        $this->assertNotSame($first->contentHash(), $changed->contentHash());
        $this->assertNotSame($first->contentHash(), $moved->contentHash());
    }

    #[Test]
    public function binary_files_are_kept_as_raw_bytes(): void
    {
        $bytes = "\x89PNG\r\n\x1a\n\x00\xff\xfe";
        $skill = new Skill(['SKILL.md' => self::LAYOUT_SKILL, 'assets/logo.png' => $bytes, 'references/grids.md' => '# Grids']);

        $this->assertSame($bytes, $skill->files['assets/logo.png']);
        $this->assertSame($bytes, $skill->asset('logo.png'));
        $this->assertTrue($skill->valid());
    }

    #[Test]
    public function a_skill_refuses_unsafe_paths_and_missing_skill_files(): void
    {
        foreach (['../SKILL.md', '/etc/passwd', 'a//b', 'a/./b', "a\0b", 'a\\b', 'C:/x'] as $path) {
            try {
                new Skill(['SKILL.md' => self::LAYOUT_SKILL, $path => 'x']);
                $this->fail("accepted {$path}");
            } catch (UnsafePathException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectExceptionMessage('has no SKILL.md');
        new Skill(['README.md' => 'x']);
    }

    #[Test]
    public function allowed_tools_are_split_on_whitespace(): void
    {
        $skill = new Skill(['SKILL.md' => "---\nname: a\ndescription: b\nallowed-tools: Read  Grep\n---\n"]);

        $this->assertSame(['Read', 'Grep'], $skill->allowedTools());
    }
}
