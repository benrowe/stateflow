<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Action;

use BenRowe\StateFlow\State;
use BenRowe\StateFlow\TransitionContext;

readonly class ActionContext
{
    /**
     * @param array<string, mixed> $desiredDelta
     */
    public function __construct(
        public State $currentState,
        public array $desiredDelta,
        public TransitionContext $executionContext,
    ) {
    }
}
