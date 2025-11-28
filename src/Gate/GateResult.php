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

    }

    public function shouldSkipAction(): bool
    {

    }
}
