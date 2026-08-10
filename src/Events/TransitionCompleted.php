<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Events;

use CoverGenius\StateFlow\State;
use CoverGenius\StateFlow\TransitionContext;

class TransitionCompleted extends Event
{
    public function __construct(
        public State $finalState,
        public TransitionContext $context,
    ) {
        parent::__construct();
    }
}
