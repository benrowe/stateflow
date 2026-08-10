<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Events;

use CoverGenius\StateFlow\Delta;
use CoverGenius\StateFlow\State;

class TransitionStarting extends Event
{
    public function __construct(
        public State $currentState,
        public Delta $desiredDelta,
    ) {
        parent::__construct();
    }
}
