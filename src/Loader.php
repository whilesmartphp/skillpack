<?php

namespace Whilesmart\Skillpack;

use Whilesmart\Skillpack\Contracts\SkillSource;
use Whilesmart\Skillpack\Net\Url;
use Whilesmart\Skillpack\Sources\FilesSource;
use Whilesmart\Skillpack\Sources\LocalSource;
use Whilesmart\Skillpack\Sources\RemoteSource;

/**
 * Entry point for loading skills. Loading reads and parses; it never runs anything a skill
 * holds and does not validate it. Call Skill::validate() for that.
 */
final class Loader
{
    /**
     * Loads a folder path, an http(s) address or a SkillSource. Remote addresses need
     * LoadOptions::$allowedHosts; folder paths must stay inside LoadOptions::$allowedRoots when
     * any are set. A SkillSource manages its own authorization and limits, so options are not
     * passed to it.
     */
    public static function load(string|SkillSource $source, ?LoadOptions $options = null): Pack
    {
        return self::source($source, $options)->load();
    }

    public static function source(string|SkillSource $source, ?LoadOptions $options = null): SkillSource
    {
        if ($source instanceof SkillSource) {
            return $source;
        }
        $options ??= new LoadOptions;

        return Url::isWeb($source) ? new RemoteSource($source, $options) : new LocalSource($source, $options);
    }

    /**
     * @param  array<string, string>  $files  Relative path => raw bytes
     * @param  array<string, mixed>  $provenance  Kept on every skill; see Skill for the keys it reads
     */
    public static function fromFiles(array $files, array $provenance = [], ?LoadOptions $options = null): Pack
    {
        return (new FilesSource($files, $provenance, $options))->load();
    }
}
