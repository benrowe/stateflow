<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Action;

use CoverGenius\StateFlow\Delta;
use CoverGenius\StateFlow\State;
use CoverGenius\StateFlow\TransitionContext;

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
