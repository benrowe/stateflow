<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Events;

use BenRowe\StateFlow\State;
use BenRowe\StateFlow\TransitionContext;
use Throwable;

class TransitionFailed extends Event
{
    public function __construct(
        public State $currentState,
        public Throwable $exception,
        public TransitionContext $context,
    ) {
        parent::__construct();
    }
}
