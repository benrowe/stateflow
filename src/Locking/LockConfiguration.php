<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Locking;

/**
 * Configuration for lock behavior
 */
readonly class LockConfiguration
{
    public function __construct(
        public LockStrategy $strategy = LockStrategy::FAIL_FAST,
        public int $ttl = 30,
        public int $waitTimeout = 10,
        public int $retryInterval = 100,
    ) {}
}
