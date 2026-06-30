<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

/**
 * Tracks execution status for state transitions.
 *
 * Manages workflow status flags (completed, paused, stopped, skipped due to lock)
 * and associated metadata for pause/stop reasons.
 */
final class ExecutionStatus
{
    private bool $completed = false;

    private bool $paused = false;

    private bool $stopped = false;

    private bool $yielded = false;

    private bool $skippedDueToLock = false;

    private mixed $metadata = null;

    /**
     * Mark the execution as completed.
     *
     * Clears all other status flags and metadata.
     */
    public function markCompleted(): void
    {
        $this->completed = true;
        $this->paused = false;
        $this->stopped = false;
        $this->yielded = false;
        $this->metadata = null;
    }

    /**
     * Mark the execution as paused.
     *
     * @param mixed $metadata Optional metadata about why the execution was paused
     */
    public function markPaused(mixed $metadata = null): void
    {
        $this->paused = true;
        $this->completed = false;
        $this->stopped = false;
        $this->yielded = false;
        $this->metadata = $metadata;
    }

    /**
     * Mark the execution as stopped.
     *
     * @param mixed $metadata Optional metadata about why the execution was stopped
     */
    public function markStopped(mixed $metadata = null): void
    {
        $this->stopped = true;
        $this->completed = false;
        $this->paused = false;
        $this->yielded = false;
        $this->metadata = $metadata;
    }

    /**
     * Mark the execution as yielded.
     *
     * @param mixed $metadata Optional metadata about why the execution was yielded
     */
    public function markYielded(mixed $metadata = null): void
    {
        $this->yielded = true;
        $this->completed = false;
        $this->paused = false;
        $this->stopped = false;
        $this->metadata = $metadata;
    }

    /**
     * Mark the execution as skipped due to lock acquisition failure.
     */
    public function markSkippedDueToLock(): void
    {
        $this->skippedDueToLock = true;
    }

    /**
     * Clear the paused status.
     *
     * Only clears if currently paused. Does not affect stopped or completed states.
     */
    public function clearPauseStatus(): void
    {
        if ($this->paused) {
            $this->paused = false;
            $this->metadata = null;
        }
    }

    /**
     * Clear the yielded status.
     *
     * Only clears if currently yielded. Does not affect stopped, paused, or completed states.
     */
    public function clearYieldStatus(): void
    {
        if ($this->yielded) {
            $this->yielded = false;
            $this->metadata = null;
        }
    }

    /**
     * Check if the execution is completed.
     */
    public function isCompleted(): bool
    {
        return $this->completed;
    }

    /**
     * Check if the execution is paused.
     */
    public function isPaused(): bool
    {
        return $this->paused;
    }

    /**
     * Check if the execution is stopped.
     */
    public function isStopped(): bool
    {
        return $this->stopped;
    }

    /**
     * Check if the execution is yielded.
     */
    public function isYielded(): bool
    {
        return $this->yielded;
    }

    /**
     * Check if the execution was skipped due to lock acquisition failure.
     */
    public function wasSkippedDueToLock(): bool
    {
        return $this->skippedDueToLock;
    }

    /**
     * Get metadata associated with the current status.
     *
     * Returns null if no metadata is set or if the execution is completed.
     */
    public function getMetadata(): mixed
    {
        return $this->metadata;
    }
}
