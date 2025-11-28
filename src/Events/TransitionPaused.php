<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Events;

use BenRowe\StateFlow\State;
use BenRowe\StateFlow\TransitionContext;

class TransitionPaused extends Event
{
    public function __construct(
        public State $currentState,
        public TransitionContext $context,
        public mixed $metadata,
    ) {
        parent::__construct();
    }
}
