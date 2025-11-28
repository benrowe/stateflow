<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Events;

use BenRowe\StateFlow\State;

class TransitionStarting extends Event
{
    public function __construct(
        public State $currentState,
        public array $desiredDelta,
    ) {
        parent::__construct();
    }
}
