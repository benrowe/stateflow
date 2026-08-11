<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Events;

use CoverGenius\StateFlow\State;
use CoverGenius\StateFlow\TransitionContext;

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
