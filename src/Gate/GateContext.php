<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Gate;

use CoverGenius\StateFlow\Delta;
use CoverGenius\StateFlow\State;

readonly class GateContext
{
    public function __construct(public State $currentState, public Delta $desiredDelta) {}
}
