<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Gate;

use Doctrine\Common\Collections\ArrayCollection;
use InvalidArgumentException;

/**
 * Immutable typed collection for Gate objects.
 *
 * Provides runtime type safety for Gate collections with a readonly, immutable interface.
 *
 * @extends ArrayCollection<int, Gate>
 */
final class GateCollection extends ArrayCollection
{
    /**
     * Create a new immutable gate collection.
     *
     * @param Gate ...$gates
     */
    public function __construct(Gate ...$gates)
    {
        parent::__construct($gates);
    }

    /**
     * Create an empty collection.
     *
     * @return self
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Create a collection from an array of gates.
     *
     * @param Gate[] $gates
     * @return self
     */
    public static function fromArray(array $gates): self
    {
        return new self(...$gates);
    }

    /**
     * Add a gate and return a new collection (immutable).
     *
     * @param Gate $gate
     * @return self
     */
    public function with(Gate $gate): self
    {
        $new = clone $this;
        $new->set($this->count(), $gate);

        return $new;
    }

    /**
     * Get all gates as an array.
     *
     * @return Gate[]
     */
    public function toArray(): array
    {
        return parent::toArray();
    }

    /**
     * Override set to ensure type safety.
     *
     * @param int $key
     * @param Gate $value
     */
    public function set($key, $value): void
    {
        if (!$value instanceof Gate) {
            throw new InvalidArgumentException('Value must be an instance of Gate');
        }

        parent::set($key, $value);
    }

    /**
     * Override offsetSet to ensure type safety.
     *
     * @param mixed $offset
     * @param Gate $value
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!$value instanceof Gate) {
            throw new InvalidArgumentException('Value must be an instance of Gate');
        }

        parent::offsetSet($offset, $value);
    }
}
