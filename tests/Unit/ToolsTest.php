<?php

namespace Whilesmart\Skillpack\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Whilesmart\Skillpack\Exceptions\SkillNotFoundException;
use Whilesmart\Skillpack\Exceptions\ToolArgumentException;
use Whilesmart\Skillpack\Exceptions\UnsafePathException;
use Whilesmart\Skillpack\Guidance;
use Whilesmart\Skillpack\Loader;
use Whilesmart\Skillpack\Pack;
use Whilesmart\Skillpack\Tests\TestCase;

class ToolsTest extends TestCase
{
    private function pack(): Pack
    {
        return Loader::load(self::FIXTURES.'/skills');
    }

    #[Test]
    public function tools_keep_their_names_and_json_schemas(): void
    {
        $tools = $this->pack()->tools();

        $this->assertSame(['list_skills', 'read_skill', 'read_reference'], array_map(fn ($tool) => $tool->name, $tools));
        $this->assertSame('{"type":"object","properties":{},"additionalProperties":false}', $tools[0]->parametersJson());
        $this->assertSame(
            '{"type":"object","properties":{"name":{"type":"string","enum":["data-modelling","page-layout"]}},"required":["name"],"additionalProperties":false}',
            $tools[1]->parametersJson(),
        );
        $this->assertSame(
            '{"type":"object","properties":{"skill":{"type":"string","enum":["data-modelling","page-layout"]},"name":{"type":"string"}},"required":["skill","name"],"additionalProperties":false}',
            $tools[2]->parametersJson(),
        );
        $this->assertSame('List the skills you can draw on, each with when it applies.', $tools[0]->description);
        $this->assertSame(['name', 'description', 'parameters'], array_keys($tools[1]->schema()));
    }

    #[Test]
    public function tools_serve_skills_to_a_model(): void
    {
        $pack = $this->pack();

        $this->assertSame($pack->indexText(), $pack->tool('list_skills')->call(null));
        $this->assertSame($pack->indexText(), $pack->tool('list_skills')->call('{}'));

        $read = $pack->tool('read_skill')->call('{"name":"page-layout"}');
        $this->assertStringStartsWith('# Page layout', $read);
        $this->assertStringEndsWith("\n\nReferences: grids.md", $read);

        $this->assertStringStartsWith('# Grids', $pack->tool('read_reference')->call(['skill' => 'page-layout', 'name' => 'grids']));
    }

    #[Test]
    public function tools_refuse_arguments_the_schema_does_not_declare(): void
    {
        $tool = $this->pack()->tool('read_skill');

        foreach (['{}', '{"name":"page-layout","extra":"x"}', '{"name":3}', '[]', '["page-layout"]', '{nope', ['page-layout']] as $bad) {
            try {
                $tool->call($bad);
                $this->fail('read_skill accepted '.json_encode($bad));
            } catch (ToolArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(ToolArgumentException::class);
        $this->pack()->tool('list_skills')->call('{"name":"x"}');
    }

    #[Test]
    public function tools_report_unknown_skills_and_escaping_references(): void
    {
        $pack = $this->pack();

        try {
            $pack->tool('read_skill')->call('{"name":"nope"}');
            $this->fail('read an unknown skill');
        } catch (SkillNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(UnsafePathException::class);
        $pack->tool('read_reference')->call('{"skill":"page-layout","name":"../SKILL.md"}');
    }

    #[Test]
    public function guidance_offers_skills_as_craft(): void
    {
        $skill = $this->pack()->get('page-layout');
        $text = Guidance::build([$skill], '  Build a pricing page  ', ['hero on the left', ' ']);

        foreach ([
            "## Task\n\nBuild a pricing page\n\n## Skill: page-layout\n\nLay out a web page",
            '# Page layout',
            "Checks for this skill:\n- primary-action: The page's main action is visible without scrolling\n- tap-targets\n\n",
            "## Already used\n\nRecent work already made these choices. Pick something else unless the task asks for one of them:\n- hero on the left\n\n",
            '{"check": "<check id>", "items": ["<what it affects>"], "why": "<reason>"}.',
        ] as $part) {
            $this->assertStringContainsString($part, $text);
        }

        $this->assertStringNotContainsString('# Page layout', Guidance::build([$skill], 'x', namesOnly: true));
    }
}
