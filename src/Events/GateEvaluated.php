<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Events;

use CoverGenius\StateFlow\Gate\Gate;
use CoverGenius\StateFlow\Gate\GateContext;
use CoverGenius\StateFlow\Gate\GateResult;

class GateEvaluated extends Event
{
    public function __construct(
        public Gate $gate,
        public GateContext $context,
        public GateResult $result,
        public bool $isActionGate,
    ) {
        parent::__construct();
    }
}
