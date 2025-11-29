<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Configuration;

use BenRowe\StateFlow\State;
use Closure;

readonly class CallableConfigurationProvider implements ConfigurationProvider
{
    public function __construct(private Closure $callable)
    {
    }

    public function provide(State $currentState, array $desiredDelta): Configuration
    {
        return ($this->callable)($currentState, $desiredDelta);
    }
}
