<?php

namespace Whilesmart\Skillpack\Contracts;

use Whilesmart\Skillpack\Pack;

interface SkillSource
{
    public function load(): Pack;
}
