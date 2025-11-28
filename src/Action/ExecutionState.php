<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Action;

enum ExecutionState
{
    case CONTINUE;
    case PAUSE;
    case STOP;
}
