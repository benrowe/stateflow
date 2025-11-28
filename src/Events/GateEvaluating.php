<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Events;

use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateContext;

class GateEvaluating extends Event
{
    public function __construct(
        public Gate $gate,
        public GateContext $context,
        public bool $isActionGate,
    ) {
        parent::__construct();
    }
}
