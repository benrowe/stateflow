<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Action;

use BenRowe\StateFlow\Delta;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\TransitionContext;

readonly class ActionContext
{
    public function __construct(
        public State $currentState,
        public Delta $desiredDelta,
        public TransitionContext $executionContext,
    ) {}
}
