<?php

namespace Whilesmart\Skillpack;

/**
 * Builds a prompt that offers skills as craft for one task and asks the model to record a
 * departure (check id, items, reason) when it goes against a check on purpose.
 */
final class Guidance
{
    private const FRAMING = 'The skills below are craft to draw on, not a routine to follow. Their practices are heuristics: '
        ."choose the ones that serve this piece of work, adapt them, and leave out what does not fit.\n\n"
        .'When you go against one of a skill\'s checks on purpose, record a departure: the check id, the '
        .'items it affects, and why. A departure asks for an exception; it does not remove the finding. '
        .'You do not need a departure for anything you simply did not use.';

    /**
     * @param  array<Skill>  $skills
     * @param  array<string>  $alreadyUsed  Choices recent work already made
     * @param  bool  $namesOnly  Leave out skill bodies, for a model that reads them through the tools
     */
    public static function build(array $skills, string $task, array $alreadyUsed = [], bool $namesOnly = false): string
    {
        $parts = [self::FRAMING, "## Task\n\n".trim($task)];
        foreach ($skills as $skill) {
            $section = '## Skill: '.$skill->name()."\n\n".$skill->description();
            if (! $namesOnly) {
                $section .= "\n\n".trim($skill->body);
            }
            if (($checks = $skill->checks()) !== []) {
                $lines = array_map(
                    fn (array $check) => rtrim('- '.$check['id'].': '.$check['description'], ': '),
                    $checks,
                );
                $section .= "\n\nChecks for this skill:\n".implode("\n", $lines);
            }
            $parts[] = $section;
        }

        $used = [];
        foreach ($alreadyUsed as $item) {
            if (($item = trim((string) $item)) !== '') {
                $used[] = '- '.$item;
            }
        }
        if ($used !== []) {
            $parts[] = "## Already used\n\nRecent work already made these choices. Pick something else "
                ."unless the task asks for one of them:\n".implode("\n", $used);
        }
        $parts[] = "## Departures\n\nIf you depart from a check, list each departure as "
            .'{"check": "<check id>", "items": ["<what it affects>"], "why": "<reason>"}.';

        return implode("\n\n", $parts);
    }
}
