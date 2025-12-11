<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Configuration;

use BenRowe\StateFlow\Delta;
use BenRowe\StateFlow\State;
use Closure;

readonly class CallableConfigurationProvider implements ConfigurationProvider
{
    /**
     * @param Closure(State, Delta): Configuration $callable
     */
    public function __construct(private Closure $callable)
    {
    }

    public function provide(State $currentState, Delta $desiredDelta): Configuration
    {
        return ($this->callable)($currentState, $desiredDelta);
    }
}
