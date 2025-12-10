<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Locking;

/**
 * Represents the state of a lock during a transition
 */
readonly class LockState
{
    public function __construct(
        public ?string $lockKey = null,
        public ?float $acquiredAt = null,
        public ?int $ttl = null,
    ) {
    }

    /**
     * Check if a lock is currently held
     */
    public function isLocked(): bool
    {
        return $this->lockKey !== null && $this->acquiredAt !== null;
    }

    /**
     * Convert lock state to array for serialization
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lockKey' => $this->lockKey,
            'acquiredAt' => $this->acquiredAt,
            'ttl' => $this->ttl,
        ];
    }

    /**
     * Create lock state from array
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $lockKey = isset($data['lockKey']) && is_string($data['lockKey']) ? $data['lockKey'] : null;
        $acquiredAt = isset($data['acquiredAt']) && (is_float($data['acquiredAt']) || is_int($data['acquiredAt']))
            ? (float) $data['acquiredAt']
            : null;
        $ttl = isset($data['ttl']) && is_int($data['ttl']) ? $data['ttl'] : null;

        return new self($lockKey, $acquiredAt, $ttl);
    }
}
