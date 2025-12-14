<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Gate\GateResult;

readonly class ActionSkip
{
    public float $timestamp;

    public function __construct(
        public Action $action,
        public GateResult $gateResult,
        float $timestamp = 0.0,
    ) {
        $this->timestamp = $timestamp === 0.0 ? microtime(true) : $timestamp;
    }
}
