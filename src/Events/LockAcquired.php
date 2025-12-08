<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Events;

use BenRowe\StateFlow\Locking\LockState;

class LockAcquired extends Event
{
    public function __construct(
        public string $lockKey,
        public LockState $lockState,
    ) {
        parent::__construct();
    }
}
