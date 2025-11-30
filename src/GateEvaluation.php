<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateResult;

readonly class GateEvaluation
{
    public function __construct(
        public Gate $gate,
        public GateResult $result,
        public bool $isActionGate,
    ) {
    }
}
