<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Action;

enum ExecutionState
{
    case CONTINUE;
    case PAUSE;
    case STOP;
    case YIELD;

    public function isYield(): bool
    {
        return $this === self::YIELD;
    }
}
