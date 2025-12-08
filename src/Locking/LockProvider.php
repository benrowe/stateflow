<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Locking;

/**
 * Interface for lock providers that manage distributed locks
 */
interface LockProvider
{
    /**
     * Acquire a lock for the given key
     *
     * @param string $key The lock key
     * @param int $ttl Time to live in seconds
     * @return bool True if lock was acquired, false otherwise
     */
    public function acquire(string $key, int $ttl = 30): bool;

    /**
     * Release a lock for the given key
     *
     * @param string $key The lock key
     * @return bool True if lock was released, false otherwise
     */
    public function release(string $key): bool;

    /**
     * Check if a lock exists for the given key
     *
     * @param string $key The lock key
     * @return bool True if lock exists, false otherwise
     */
    public function exists(string $key): bool;

    /**
     * Renew a lock for the given key
     *
     * @param string $key The lock key
     * @param int $ttl New time to live in seconds
     * @return bool True if lock was renewed, false otherwise
     */
    public function renew(string $key, int $ttl): bool;
}
