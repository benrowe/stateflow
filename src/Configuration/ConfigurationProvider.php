<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Configuration;

use CoverGenius\StateFlow\Delta;
use CoverGenius\StateFlow\State;

interface ConfigurationProvider
{
    public function provide(State $currentState, Delta $desiredDelta): Configuration;
}
