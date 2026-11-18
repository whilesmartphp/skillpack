<?php

namespace Whilesmart\Skillpack;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Whilesmart\Skillpack\Exceptions\SkillpackException;

final class Frontmatter
{
    private const BLOCK = '/\A---\r?\n(.*?)\r?\n---[ \t]*(?:\r?\n|\z)/s';

    private const PLAIN_LINE = '/^([A-Za-z][A-Za-z0-9_-]*):[ \t]+(\S.*)$/D';

    /**
     * Splits SKILL.md text into its frontmatter mapping, the body after it and, when the
     * frontmatter only read as plain "key: value" lines, the YAML error strict readers report.
     *
     * @return array{0: array<string, mixed>, 1: string, 2: ?string}
     */
    public static function split(string $text, bool $lenient = true): array
    {
        if (! preg_match(self::BLOCK, $text, $match, PREG_OFFSET_CAPTURE)) {
            throw new SkillpackException('SKILL.md has no YAML frontmatter');
        }
        $block = $match[1][0];
        $body = substr($text, $match[0][1] + strlen($match[0][0]));
        $problem = null;

        try {
            $front = Yaml::parse($block);
        } catch (ParseException $exception) {
            $plain = $lenient ? self::plainLines($block) : null;
            if ($plain === null) {
                throw new SkillpackException('Frontmatter is not valid YAML: '.self::oneLine($exception->getMessage()));
            }
            $front = $plain;
            $problem = self::oneLine($exception->getMessage());
        }

        if (! is_array($front) || ($front !== [] && array_is_list($front))) {
            throw new SkillpackException('Frontmatter must be a mapping');
        }

        $mapping = [];
        foreach ($front as $key => $value) {
            $mapping[(string) $key] = $value;
        }

        return [$mapping, $body, $problem];
    }

    /**
     * @return array<string, string>|null
     */
    private static function plainLines(string $block): ?array
    {
        $found = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $block)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (! preg_match(self::PLAIN_LINE, $line, $parts)) {
                return null;
            }
            $found[$parts[1]] = trim($parts[2]);
        }

        return $found === [] ? null : $found;
    }

    private static function oneLine(string $message): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $message));
    }
}
