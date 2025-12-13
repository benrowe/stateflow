<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Locking;

use BenRowe\StateFlow\Events\EventDispatcher;
use BenRowe\StateFlow\Events\LockAcquired;
use BenRowe\StateFlow\Events\LockFailed;
use BenRowe\StateFlow\Events\LockLost;
use BenRowe\StateFlow\Events\LockReleased;
use BenRowe\StateFlow\Events\LockRestored;
use BenRowe\StateFlow\Exceptions\LockAcquisitionException;
use BenRowe\StateFlow\Exceptions\LockLostException;
use BenRowe\StateFlow\TransitionContext;

/**
 * Manages distributed locking for transitions
 *
 * Handles lock acquisition, release, renewal, and strategy execution
 */
class LockManager
{
    public function __construct(
        private readonly ?LockProvider $lockProvider,
        private readonly ?LockKeyProvider $lockKeyProvider,
        private readonly ?LockConfiguration $lockConfiguration,
        private readonly TransitionContext $context,
        private readonly EventDispatcher $eventDispatcher,
    ) {
    }

    /**
     * Acquire a lock for the transition
     *
     * @return string|null Lock key if acquired, null if no locking configured
     */
    public function acquireLock(): ?string
    {
        // Skip if no locking configured or strategy is NONE
        if (
            $this->lockProvider === null
            || $this->lockKeyProvider === null
            || $this->lockConfiguration === null
            || $this->lockConfiguration->strategy === LockStrategy::NONE
        ) {
            return null;
        }

        // Generate lock key
        $lockKey = $this->lockKeyProvider->getLockKey(
            $this->context->getCurrentState(),
            $this->context->getDesiredDelta()
        );

        // Attempt to acquire lock
        $acquired = $this->lockProvider->acquire($lockKey, $this->lockConfiguration->ttl);

        if ($acquired) {
            // Store lock state in context
            $lockState = new LockState($lockKey, microtime(true), $this->lockConfiguration->ttl);
            $this->context->setLockState($lockState);

            // Dispatch LockAcquired event
            $this->eventDispatcher->dispatch(new LockAcquired($lockKey, $lockState));

            return $lockKey;
        }

        // Handle lock acquisition failure based on strategy
        match ($this->lockConfiguration->strategy) {
            LockStrategy::FAIL_FAST => $this->handleFailFast($lockKey),
            LockStrategy::SKIP => $this->handleSkip($lockKey),
            LockStrategy::WAIT => $this->handleWait($lockKey),
            // @codeCoverageIgnoreStart
            LockStrategy::NONE => null,
            // @codeCoverageIgnoreEnd
        };

        return null;
    }

    /**
     * Release the acquired lock
     */
    public function releaseLock(): void
    {
        // Skip if no locking configured or no lock was acquired
        // @codeCoverageIgnoreStart
        if (
            $this->lockProvider === null
            || $this->lockKeyProvider === null
            || !$this->context->lockState()->isLocked()
        ) {
            return;
        }
        // @codeCoverageIgnoreEnd

        $lockState = $this->context->lockState();
        $lockKey = $lockState->lockKey;

        assert($lockKey !== null);

        // Release the lock
        $this->lockProvider->release($lockKey);

        // Dispatch LockReleased event
        $this->eventDispatcher->dispatch(
            new LockReleased($lockKey, $this->context->getCurrentState())
        );
    }

    /**
     * Renew lock if it's about to expire
     */
    public function renewLockIfNeeded(): void
    {
        // Skip if no locking configured or no lock was acquired
        if (
            $this->lockProvider === null
            || $this->lockConfiguration === null
            || !$this->context->lockState()->isLocked()
        ) {
            return;
        }

        $lockState = $this->context->lockState();
        $acquiredAt = $lockState->acquiredAt;
        $ttl = $lockState->ttl;

        assert($acquiredAt !== null);
        assert($ttl !== null);

        // Calculate how much time has elapsed since lock was acquired
        $elapsed = microtime(true) - $acquiredAt;

        // Renew if more than 50% of TTL has elapsed
        $renewThreshold = $ttl * 0.5;

        if ($elapsed >= $renewThreshold) {
            $lockKey = $lockState->lockKey;
            assert($lockKey !== null);

            // Renew the lock
            $renewed = $this->lockProvider->renew($lockKey, $ttl);

            if ($renewed) {
                // Update lock state with new acquired time
                $newLockState = new LockState($lockKey, microtime(true), $ttl);
                $this->context->setLockState($newLockState);

                // Dispatch LockRestored event
                $this->eventDispatcher->dispatch(
                    new LockRestored($lockKey, $this->context->getCurrentState())
                );
            }
        }
    }

    /**
     * Check if lock is still held, throw exception if lost
     */
    public function checkLockStillHeld(): void
    {
        // Skip if no locking configured or no lock was acquired
        if (
            $this->lockProvider === null
            || !$this->context->lockState()->isLocked()
        ) {
            return;
        }

        $lockState = $this->context->lockState();
        $lockKey = $lockState->lockKey;

        assert($lockKey !== null);

        // Check if lock still exists
        $lockExists = $this->lockProvider->exists($lockKey);

        if (!$lockExists) {
            // Dispatch LockLost event
            $this->eventDispatcher->dispatch(
                new LockLost($lockKey, $this->context->getCurrentState())
            );

            // Throw exception to stop execution
            throw new LockLostException(
                sprintf('Lock was lost during execution for key: %s', $lockKey)
            );
        }
    }

    private function handleFailFast(string $lockKey): void
    {
        // Dispatch LockFailed event
        $this->eventDispatcher->dispatch(
            new LockFailed(
                $lockKey,
                $this->context->getCurrentState(),
                'Lock is already held by another process'
            )
        );

        // Throw exception to stop execution
        throw new LockAcquisitionException(
            sprintf('Failed to acquire lock for key: %s', $lockKey)
        );
    }

    private function handleSkip(string $lockKey): void
    {
        // Dispatch LockFailed event
        $this->eventDispatcher->dispatch(
            new LockFailed(
                $lockKey,
                $this->context->getCurrentState(),
                'Lock is already held by another process'
            )
        );

        // Mark context as skipped due to lock
        $this->context->executionStatus()->markSkippedDueToLock();
    }

    private function handleWait(string $lockKey): void
    {
        assert($this->lockProvider !== null);
        assert($this->lockConfiguration !== null);

        $startTime = microtime(true);
        $timeoutSeconds = $this->lockConfiguration->waitTimeout;
        $retryIntervalMs = $this->lockConfiguration->retryInterval;

        // Keep retrying until timeout
        while (true) {
            // Check if timeout has been reached
            $elapsed = microtime(true) - $startTime;
            if ($elapsed >= $timeoutSeconds) {
                // Dispatch LockFailed event
                $this->eventDispatcher->dispatch(
                    new LockFailed(
                        $lockKey,
                        $this->context->getCurrentState(),
                        sprintf('Failed to acquire lock after %.2f seconds', $elapsed)
                    )
                );

                throw new LockAcquisitionException(
                    sprintf('Failed to acquire lock for key: %s after %.2f seconds', $lockKey, $elapsed)
                );
            }

            // Sleep before retrying
            usleep($retryIntervalMs * 1000); // Convert milliseconds to microseconds

            // Try to acquire lock again
            $acquired = $this->lockProvider->acquire($lockKey, $this->lockConfiguration->ttl);

            if ($acquired) {
                // Store lock state in context
                $lockState = new LockState($lockKey, microtime(true), $this->lockConfiguration->ttl);
                $this->context->setLockState($lockState);

                // Dispatch LockAcquired event
                $this->eventDispatcher->dispatch(new LockAcquired($lockKey, $lockState));

                return;
            }
        }
    }
}
