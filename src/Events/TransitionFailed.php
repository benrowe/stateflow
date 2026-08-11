<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Events;

use CoverGenius\StateFlow\State;
use CoverGenius\StateFlow\TransitionContext;
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
