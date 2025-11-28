<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Events;

use BenRowe\StateFlow\State;

class LockFailed extends Event
{
    public function __construct(
        public string $lockKey,
        public State $state,
        public string $reason,
    ) {
        parent::__construct();
    }
}
