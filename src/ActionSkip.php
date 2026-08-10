<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow;

use CoverGenius\StateFlow\Action\Action;
use CoverGenius\StateFlow\Gate\GateResult;

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
