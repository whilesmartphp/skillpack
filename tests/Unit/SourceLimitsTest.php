<?php

namespace Whilesmart\Skillpack\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Whilesmart\Skillpack\Exceptions\LimitExceededException;
use Whilesmart\Skillpack\Exceptions\ToolArgumentException;
use Whilesmart\Skillpack\Loader;
use Whilesmart\Skillpack\LoadOptions;
use Whilesmart\Skillpack\Tests\Support\Archives;
use Whilesmart\Skillpack\Tests\Support\FakeResolver;
use Whilesmart\Skillpack\Tests\Support\FakeTransport;
use Whilesmart\Skillpack\Tests\TestCase;

class SourceLimitsTest extends TestCase
{
    #[Test]
    public function numeric_metadata_keys_are_invalid(): void
    {
        $skill = Loader::fromFiles(['SKILL.md' => "---\nname: code-review\ndescription: Review code.\nmetadata:\n  42: text\n---\nGuidance.\n"])->get('code-review');

        $this->assertContains('metadata-type', $this->codes($skill->validate()));
        $this->assertFalse($skill->valid());
    }

    #[Test]
    public function direct_archives_enforce_skill_limits(): void
    {
        foreach (['zip', 'tarGz'] as $format) {
            $files = ['bundle/code-review/SKILL.md' => self::REVIEW_SKILL, 'bundle/page-layout/SKILL.md' => self::LAYOUT_SKILL];
            $data = Archives::$format($files);
            $url = 'https://skills.test/bundle.'.($format === 'zip' ? 'zip' : 'tar.gz');
            $transport = (new FakeTransport)->serve($url, 'application/octet-stream', $data);
            $options = new LoadOptions(allowedHosts: ['skills.test'], maxSkills: 1, transport: $transport, resolver: new FakeResolver);
            try {
                Loader::load($url, $options);
                $this->fail('An archive exceeded its skill limit');
            } catch (LimitExceededException $exception) {
                $this->assertStringContainsString('more than 1 skills', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function uppercase_archive_suffixes_keep_the_skill_folder_name(): void
    {
        foreach (['ZIP' => 'zip', 'TAR.GZ' => 'tarGz', 'TGZ' => 'tarGz'] as $suffix => $format) {
            $data = Archives::$format(['SKILL.md' => self::REVIEW_SKILL]);
            $url = 'https://skills.test/code-review.'.$suffix;
            $transport = (new FakeTransport)->serve($url, 'application/octet-stream', $data);
            $skill = Loader::load($url, new LoadOptions(allowedHosts: ['skills.test'], transport: $transport, resolver: new FakeResolver))->get('code-review');
            $this->assertSame('code-review', $skill->folder());
            $this->assertTrue($skill->valid());
        }
    }

    #[Test]
    public function empty_directories_cannot_bypass_local_limits(): void
    {
        $root = $this->temporaryDirectory();
        $this->write($root.'/SKILL.md', self::REVIEW_SKILL);
        mkdir($root.'/empty-one');
        mkdir($root.'/empty-two');

        $this->expectException(LimitExceededException::class);
        Loader::load($root, new LoadOptions(maxFiles: 2));
    }

    #[Test]
    public function empty_directories_at_the_catalogue_root_are_bounded(): void
    {
        $root = $this->temporaryDirectory();
        mkdir($root.'/one');
        mkdir($root.'/two');

        $this->expectException(LimitExceededException::class);
        Loader::load($root, new LoadOptions(maxFiles: 1));
    }

    #[Test]
    public function model_tools_reject_null_and_empty_json_strings(): void
    {
        $tool = Loader::fromFiles(['SKILL.md' => self::REVIEW_SKILL])->tool('list_skills');
        foreach (['null', ' null ', '', '  '] as $arguments) {
            try {
                $tool->call($arguments);
                $this->fail('A tool accepted non-object JSON');
            } catch (ToolArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($tool->call('{}'), $tool->call(null));
    }

    #[Test]
    public function skipped_archive_directories_do_not_hide_wrapped_skills(): void
    {
        $url = 'https://skills.test/bundle.zip';
        $data = Archives::zip(['node_modules/' => '', 'node_modules/pkg/' => '', 'bundle/code-review/SKILL.md' => self::REVIEW_SKILL]);
        $transport = (new FakeTransport)->serve($url, 'application/zip', $data);

        $pack = Loader::load($url, new LoadOptions(allowedHosts: ['skills.test'], transport: $transport, resolver: new FakeResolver));
        $this->assertSame(['code-review'], array_column($pack->index(), 'name'));
    }
}
