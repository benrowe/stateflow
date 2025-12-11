<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Configuration;

use BenRowe\StateFlow\Delta;
use BenRowe\StateFlow\State;

interface ConfigurationProvider
{
    public function provide(State $currentState, Delta $desiredDelta): Configuration;
}
