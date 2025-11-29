<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Events;

use BenRowe\StateFlow\State;

class TransitionStarting extends Event
{
    /**
     * @param array<string, mixed> $desiredDelta
     */
    public function __construct(
        public State $currentState,
        public array $desiredDelta,
    ) {
        parent::__construct();
    }
}
