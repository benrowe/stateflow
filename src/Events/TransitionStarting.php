<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Events;

use BenRowe\StateFlow\Delta;
use BenRowe\StateFlow\State;

class TransitionStarting extends Event
{
    public function __construct(
        public State $currentState,
        public Delta $desiredDelta,
    ) {
        parent::__construct();
    }
}
