<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Locking;

/**
 * Strategies for handling lock acquisition failures
 */
enum LockStrategy
{
    /**
     * No locking - transitions run without acquiring locks
     */
    case NONE;

    /**
     * Fail immediately if lock cannot be acquired
     * Throws LockAcquisitionException
     */
    case FAIL_FAST;

    /**
     * Wait and retry until lock is acquired or timeout is reached
     * Throws LockAcquisitionException on timeout
     */
    case WAIT;

    /**
     * Skip the transition silently if lock cannot be acquired
     * No exception is thrown, transition is marked as skipped
     */
    case SKIP;
}
