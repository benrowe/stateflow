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
        private ?YieldResponse $yieldResponse = null,
    ) {}

    public function hasYieldResponse(): bool
    {
        return $this->yieldResponse !== null;
    }

    public function yieldResponse(): mixed
    {
        return $this->yieldResponse?->data;
    }
}
