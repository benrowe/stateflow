<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Locking;

/**
 * Bundles all locking dependencies into a single context
 *
 * This eliminates the need to pass three separate nullable locking dependencies
 * and makes it impossible to provide partial locking configuration.
 */
readonly class LockContext
{
    public function __construct(
        public LockProvider $provider,
        public LockKeyProvider $keyProvider,
        public LockConfiguration $configuration,
    ) {
    }
}
