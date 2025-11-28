<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Events;

use BenRowe\StateFlow\State;

class LockLost extends Event
{
    public function __construct(
        public string $lockKey,
        public State $state,
    ) {
        parent::__construct();
    }
}
