<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Configuration;

use Closure;
use CoverGenius\StateFlow\Delta;
use CoverGenius\StateFlow\State;

readonly class CallableConfigurationProvider implements ConfigurationProvider
{
    /**
     * @param Closure(State, Delta): Configuration $callable
     */
    public function __construct(private Closure $callable) {}

    public function provide(State $currentState, Delta $desiredDelta): Configuration
    {
        return ($this->callable)($currentState, $desiredDelta);
    }
}
