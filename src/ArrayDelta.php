<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow;

use JsonSerializable;

/**
 * Default array-based implementation of Delta.
 *
 * Provides a simple, immutable container for state changes backed by an array.
 */
final readonly class ArrayDelta implements Delta, JsonSerializable
{
    /**
     * @param array<string, mixed> $changes
     */
    public function __construct(private array $changes = []) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->changes);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->changes) ? $this->changes[$key] : $default;
    }

    public function asArray(): array
    {
        return $this->changes;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->changes;
    }
}
