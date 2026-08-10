<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Events;

use CoverGenius\StateFlow\Action\Action;
use CoverGenius\StateFlow\Gate\GateResult;

class ActionSkipped extends Event
{
    public function __construct(
        public Action $action,
        public GateResult $gateResult,
    ) {
        parent::__construct();
    }
}
