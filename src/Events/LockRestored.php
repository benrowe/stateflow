<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Events;

use CoverGenius\StateFlow\State;

class LockRestored extends Event
{
    public function __construct(
        public string $lockKey,
        public State $state,
    ) {
        parent::__construct();
    }
}
