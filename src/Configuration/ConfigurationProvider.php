<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Configuration;

use BenRowe\StateFlow\State;

interface ConfigurationProvider
{
    /**
     * @param array<string, mixed> $desiredDelta
     */
    public function provide(State $currentState, array $desiredDelta): Configuration;
}
