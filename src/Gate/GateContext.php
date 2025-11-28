<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Gate;

use BenRowe\StateFlow\State;

readonly class GateContext
{
    /**
     * @param array<string, mixed> $desiredState
     */
    public function __construct(public State $currentState, public array $desiredState)
    {
    }
}
