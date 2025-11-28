<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Events;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Gate\GateResult;

class ActionSkipped extends Event
{
    public function __construct(
        public Action $action,
        public GateResult $gateResult,
    ) {
        parent::__construct();
    }
}
