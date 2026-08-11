<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow;

/**
 * Represents a set of state changes to be applied during a transition.
 *
 * Delta provides a type-safe way to handle state changes, offering:
 * - Type safety - can't pass wrong type
 * - Encapsulation - controlled access to changes
 * - Rich API - helper methods like has(), get()
 * - Self-documenting code
 */
interface Delta
{
    /**
     * Check if a key exists in the delta
     */
    public function has(string $key): bool;

    /**
     * Get a value from the delta, optionally with a default
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Convert the delta to an array representation
     *
     * @return array<string, mixed>
     */
    public function asArray(): array;
}
