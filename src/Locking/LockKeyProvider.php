<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Locking;

use CoverGenius\StateFlow\Delta;
use CoverGenius\StateFlow\State;

/**
 * Interface for generating lock keys based on state and delta
 */
interface LockKeyProvider
{
    /**
     * Generate a lock key for the given state and desired delta
     *
     * @param State $state The current state
     * @param Delta $desiredDelta The desired changes
     * @return string The lock key
     */
    public function getLockKey(State $state, Delta $desiredDelta): string;
}
