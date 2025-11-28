<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Gate;

enum GateResult
{
    case ALLOW;
    case DENY;
    case SKIP_IDEMPOTENT;

    public function shouldStopTransition(): bool
    {
        return $this === self::DENY;
    }

    public function shouldSkipAction(): bool
    {
        return $this === self::DENY || $this === self::SKIP_IDEMPOTENT;
    }
}
