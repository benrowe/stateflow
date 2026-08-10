<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Events;

use CoverGenius\StateFlow\Gate\Gate;
use CoverGenius\StateFlow\Gate\GateContext;

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
