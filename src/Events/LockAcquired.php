<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Events;

use CoverGenius\StateFlow\Locking\LockState;

class LockAcquired extends Event
{
    public function __construct(
        public string $lockKey,
        public LockState $lockState,
    ) {
        parent::__construct();
    }
}
