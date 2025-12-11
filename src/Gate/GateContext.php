<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Gate;

use BenRowe\StateFlow\Delta;
use BenRowe\StateFlow\State;

readonly class GateContext
{
    public function __construct(public State $currentState, public Delta $desiredDelta)
    {
    }
}
