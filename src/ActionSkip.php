<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Gate\GateResult;

readonly class ActionSkip
{
    public function __construct(
        public Action $action,
        public GateResult $gateResult,
    ) {
    }
}
