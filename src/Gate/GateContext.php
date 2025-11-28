<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Gate;

readonly class GateContext
{
    public function __construct(public State $currentState, public array $desiredState)
    {
    }
}
