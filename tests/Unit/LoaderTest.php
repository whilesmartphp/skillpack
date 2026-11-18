<?php

namespace Whilesmart\Skillpack\Tests\Unit;

use Error;
use PHPUnit\Framework\Attributes\Test;
use Whilesmart\Skillpack\Contracts\SkillSource;
use Whilesmart\Skillpack\Exceptions\AccessDeniedException;
use Whilesmart\Skillpack\Exceptions\InvalidOptionsException;
use Whilesmart\Skillpack\Exceptions\LimitExceededException;
use Whilesmart\Skillpack\Exceptions\SkillNotFoundException;
use Whilesmart\Skillpack\Exceptions\SkillpackException;
use Whilesmart\Skillpack\Exceptions\UnsafePathException;
use Whilesmart\Skillpack\Loader;
use Whilesmart\Skillpack\LoadOptions;
use Whilesmart\Skillpack\Pack;
use Whilesmart\Skillpack\Skill;
use Whilesmart\Skillpack\Tests\TestCase;

class LoaderTest extends TestCase
{
    #[Test]
    public function a_folder_loads_each_child_skill_in_name_order(): void
    {
        $pack = Loader::load(self::FIXTURES.'/skills');

        $this->assertSame([
            ['name' => 'data-modelling', 'description' => 'Model entities and their relations before writing tables.'],
            ['name' => 'page-layout', 'description' => 'Lay out a web page so its most important action is found first, at any screen width.'],
        ], $pack->index());
        $this->assertStringStartsWith('- data-modelling: Model entities', $pack->indexText());
        $this->assertSame(1, substr_count($pack->indexText(), "\n"));
        $this->assertSame([], $pack->problems());
        $this->assertCount(2, $pack);
    }

    #[Test]
    public function a_skill_folder_or_its_skill_file_loads_one_skill(): void
    {
        foreach ([self::FIXTURES.'/skills/page-layout', self::FIXTURES.'/skills/page-layout/SKILL.md'] as $path) {
            $skill = Loader::load($path)->get('page-layout');

            $this->assertSame('page-layout', $skill->folder());
            $this->assertTrue($skill->valid(), $path);
            $this->assertSame('local', $skill->provenance['type']);
        }
    }

    #[Test]
    public function a_relative_skill_is_checked_against_its_real_folder(): void
    {
        $previous = getcwd();
        chdir(self::FIXTURES.'/skills/page-layout');
        try {
            $skill = Loader::load('.')->get('page-layout');
        } finally {
            chdir((string) $previous);
        }

        $this->assertTrue($skill->valid());
    }

    #[Test]
    public function duplicate_names_are_refused_and_missing_names_listed(): void
    {
        $layout = Loader::load(self::FIXTURES.'/skills/page-layout')->get('page-layout');
        $all = Loader::load(self::FIXTURES.'/skills');

        try {
            new Pack([...$all->all(), $layout]);
            $this->fail('accepted two skills with one name');
        } catch (SkillpackException $exception) {
            $this->assertStringContainsString('Two skills are named page-layout', $exception->getMessage());
        }

        $this->expectException(SkillNotFoundException::class);
        $this->expectExceptionMessage('No skill named nope (have: data-modelling, page-layout)');
        $all->get('nope');
    }

    #[Test]
    public function local_paths_must_stay_inside_allowed_roots(): void
    {
        $options = new LoadOptions(allowedRoots: [self::FIXTURES.'/skills']);

        $this->assertCount(2, Loader::load(self::FIXTURES.'/skills', $options));
        $this->assertCount(1, Loader::load(self::FIXTURES.'/skills/page-layout/../data-modelling', $options));

        foreach ([self::FIXTURES.'/broken/nested', self::FIXTURES.'/skills/..', '/'] as $path) {
            try {
                Loader::load($path, $options);
                $this->fail("loaded {$path} outside the allowed roots");
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function a_symlinked_child_cannot_leave_the_allowed_roots(): void
    {
        $base = $this->temporaryDirectory();
        $this->write("{$base}/outside/secret-skill/SKILL.md", "---\nname: secret-skill\ndescription: Not yours.\n---\n");
        mkdir("{$base}/allowed");
        if (! @symlink("{$base}/outside/secret-skill", "{$base}/allowed/secret-skill")) {
            $this->markTestSkipped('symbolic links are not available');
        }

        $this->expectException(AccessDeniedException::class);
        Loader::load("{$base}/allowed", new LoadOptions(allowedRoots: ["{$base}/allowed"]));
    }

    #[Test]
    public function a_symlink_out_of_a_skill_is_never_read(): void
    {
        $base = $this->temporaryDirectory();
        $root = "{$base}/page-layout";
        $this->write("{$base}/secret.md", 'private');
        $this->write("{$base}/checks.yaml", "checks:\n  - id: leaked\n");
        $this->write("{$root}/SKILL.md", self::LAYOUT_SKILL);
        mkdir("{$root}/references");
        if (! @symlink("{$base}/secret.md", "{$root}/references/grids.md")) {
            $this->markTestSkipped('symbolic links are not available');
        }
        symlink("{$base}/checks.yaml", "{$root}/checks.yaml");

        $skill = Loader::load($root)->get('page-layout');

        $this->assertNotContains('private', $skill->files);
        $this->assertSame(['checks.yaml', 'references/grids.md'], $skill->provenance['links_outside']);
        $this->assertSame(['link-outside'], $this->codes($skill->validate()));
        $this->assertSame([], $skill->checks());

        $this->expectException(UnsafePathException::class);
        $skill->reference('grids');
    }

    #[Test]
    public function a_symlink_inside_a_skill_is_followed(): void
    {
        $base = $this->temporaryDirectory();
        $root = "{$base}/page-layout";
        $this->write("{$root}/SKILL.md", self::LAYOUT_SKILL);
        $this->write("{$root}/shared/grids.md", "# Grids\n");
        mkdir("{$root}/references");
        if (! @symlink("{$root}/shared/grids.md", "{$root}/references/grids.md")) {
            $this->markTestSkipped('symbolic links are not available');
        }
        $this->write("{$root}/node_modules/dep/SKILL.md", "---\nname: dep\ndescription: x\n---\n");

        $skill = Loader::load($root)->get('page-layout');

        $this->assertSame("# Grids\n", $skill->reference('grids'));
        $this->assertTrue($skill->valid());
        $this->assertArrayNotHasKey('node_modules/dep/SKILL.md', $skill->files);
    }

    #[Test]
    public function local_loading_is_bounded(): void
    {
        $this->expectException(LimitExceededException::class);
        Loader::load(self::FIXTURES.'/skills', new LoadOptions(maxFiles: 3));
    }

    #[Test]
    public function local_loading_bounds_bytes(): void
    {
        $this->expectException(LimitExceededException::class);
        Loader::load(self::FIXTURES.'/skills', new LoadOptions(maxExtractedBytes: 100));
    }

    #[Test]
    public function a_missing_folder_and_other_schemes_are_refused(): void
    {
        foreach ([self::FIXTURES.'/nowhere', 'file:///etc/passwd', 'ftp://example.com/skills'] as $location) {
            try {
                Loader::load($location);
                $this->fail("loaded {$location}");
            } catch (SkillpackException $exception) {
                $this->assertMatchesRegularExpression('/is not a folder|not an http or https address/', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function a_folder_without_skills_loads_an_empty_pack(): void
    {
        $base = $this->temporaryDirectory();
        $this->write("{$base}/notes.txt", 'not a skill');

        $this->assertCount(0, Loader::load($base));
    }

    #[Test]
    public function files_load_a_root_skill(): void
    {
        $pack = Loader::fromFiles(
            ['SKILL.md' => self::LAYOUT_SKILL, 'references/grids.md' => "# Grids\n"],
            ['folder' => 'page-layout', 'origin' => 'upload'],
        );
        $skill = $pack->get('page-layout');

        $this->assertTrue($skill->valid());
        $this->assertSame('upload', $skill->provenance['origin']);
        $this->assertSame('files', $skill->provenance['type']);
        $this->assertSame("# Grids\n", $skill->reference('grids'));
    }

    #[Test]
    public function files_load_each_skill_folder_and_ignore_other_files(): void
    {
        $pack = Loader::fromFiles([
            'page-layout/SKILL.md' => self::LAYOUT_SKILL,
            'page-layout/references/grids.md' => "# Grids\n",
            'code-review/SKILL.md' => self::REVIEW_SKILL,
            'notes.txt' => 'not a skill',
            'deeper/still/SKILL.md' => self::REVIEW_SKILL,
        ], ['root' => 'bundle']);

        $this->assertSame(['code-review', 'page-layout'], array_column($pack->index(), 'name'));
        $layout = $pack->get('page-layout');
        $this->assertSame('page-layout', $layout->folder());
        $this->assertSame('bundle/page-layout', $layout->root());
        $this->assertSame(['SKILL.md', 'references/grids.md'], array_keys($layout->files));
        $this->assertTrue($layout->valid());
    }

    #[Test]
    public function files_name_folder_mismatches_are_reported_not_refused(): void
    {
        $pack = Loader::fromFiles(['reviewer/SKILL.md' => self::REVIEW_SKILL]);

        $this->assertSame(['name-folder'], $this->codes($pack->get('code-review')->validate()));
    }

    #[Test]
    public function files_refuse_unsafe_paths_conflicts_and_oversized_bundles(): void
    {
        $cases = [
            'traversal' => [['SKILL.md' => self::LAYOUT_SKILL, '../escape.md' => 'x'], new LoadOptions, UnsafePathException::class],
            'absolute' => [['/SKILL.md' => self::LAYOUT_SKILL], new LoadOptions, UnsafePathException::class],
            'backslash' => [['SKILL.md' => self::LAYOUT_SKILL, 'references\\a.md' => 'x'], new LoadOptions, UnsafePathException::class],
            'dot segment' => [['./SKILL.md' => self::LAYOUT_SKILL], new LoadOptions, UnsafePathException::class],
            'control character' => [["SKILL.md\n" => self::LAYOUT_SKILL], new LoadOptions, UnsafePathException::class],
            'file and folder' => [['SKILL.md' => self::LAYOUT_SKILL, 'references' => 'x', 'references/a.md' => 'y'], new LoadOptions, UnsafePathException::class],
            'too many files' => [['SKILL.md' => self::LAYOUT_SKILL, 'a.md' => 'x'], new LoadOptions(maxFiles: 1), LimitExceededException::class],
            'too many bytes' => [['SKILL.md' => self::LAYOUT_SKILL], new LoadOptions(maxExtractedBytes: 10), LimitExceededException::class],
            'too many skills' => [['a/SKILL.md' => self::LAYOUT_SKILL, 'b/SKILL.md' => self::REVIEW_SKILL], new LoadOptions(maxSkills: 1), LimitExceededException::class],
            'not bytes' => [['SKILL.md' => ['nested']], new LoadOptions, SkillpackException::class],
        ];

        foreach ($cases as $name => [$files, $options, $exception]) {
            try {
                Loader::fromFiles($files, [], $options);
                $this->fail("{$name} was accepted");
            } catch (SkillpackException $caught) {
                $this->assertInstanceOf($exception, $caught, $name);
            }
        }
    }

    #[Test]
    public function files_without_frontmatter_fail_to_load(): void
    {
        $this->expectExceptionMessage('SKILL.md has no YAML frontmatter');
        Loader::fromFiles(['SKILL.md' => 'just text']);
    }

    #[Test]
    public function a_skill_source_is_loaded_as_given(): void
    {
        $source = new class implements SkillSource
        {
            public function load(): Pack
            {
                return new Pack([new Skill(['SKILL.md' => "---\nname: custom\ndescription: From a source.\n---\n"])]);
            }
        };

        $this->assertSame('custom', Loader::load($source)->get('custom')->name());
        $this->assertSame($source, Loader::source($source));
    }

    #[Test]
    public function options_are_checked_and_immutable(): void
    {
        $options = new LoadOptions(allowedHosts: [' Example.COM ', 'example.com'], timeoutSeconds: 5);

        $this->assertSame(['example.com'], $options->allowedHosts);
        $this->assertTrue($options->allowsHost('EXAMPLE.com'));
        $this->assertFalse($options->allowPrivateAddresses);

        try {
            $options->timeoutSeconds = 10;
            $this->fail('changed a load option');
        } catch (Error) {
            $this->addToAssertionCount(1);
        }

        foreach ([
            fn () => new LoadOptions(maxFiles: 0),
            fn () => new LoadOptions(allowedHosts: ['https://example.com']),
            fn () => new LoadOptions(allowedHosts: ['']),
            fn () => new LoadOptions(directory: '../up'),
        ] as $make) {
            try {
                $make();
                $this->fail('accepted invalid options');
            } catch (InvalidOptionsException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
