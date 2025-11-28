<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Events;

use BenRowe\StateFlow\State;
use BenRowe\StateFlow\TransitionContext;

class TransitionCompleted extends Event
{
    public function __construct(
        public State $finalState,
        public TransitionContext $context,
    ) {
        parent::__construct();
    }
}
