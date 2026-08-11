<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow;

use CoverGenius\StateFlow\Gate\Gate;
use CoverGenius\StateFlow\Gate\GateResult;

readonly class GateEvaluation
{
    public float $timestamp;

    public function __construct(
        public Gate $gate,
        public GateResult $result,
        public bool $isActionGate,
        float $timestamp = 0.0,
    ) {
        $this->timestamp = $timestamp === 0.0 ? microtime(true) : $timestamp;
    }
}
