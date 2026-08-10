<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow;

use Doctrine\Common\Collections\ArrayCollection;
use InvalidArgumentException;

/**
 * Immutable typed collection for ActionSkip objects.
 *
 * Provides runtime type safety for ActionSkip collections with a readonly, immutable interface.
 *
 * @extends ArrayCollection<int, ActionSkip>
 */
final class ActionSkipCollection extends ArrayCollection
{
    /**
     * Create a new immutable action to skip the collection.
     *
     * @param ActionSkip[] $skips
     *
     * @throws InvalidArgumentException if any element is not an ActionSkip
     */
    public function __construct(array $skips = [])
    {
        foreach ($skips as $skip) {
            if (!$skip instanceof ActionSkip) {
                throw new InvalidArgumentException('All elements must be instances of ' . ActionSkip::class);
            }
        }

        parent::__construct(array_values($skips));
    }

    /**
     * Create an empty collection.
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Create a collection from an array of action skips.
     *
     * @param ActionSkip[] $skips
     */
    public static function fromArray(array $skips): self
    {
        return new self($skips);
    }

    /**
     * Add an action skip and return a new collection (immutable).
     */
    public function with(ActionSkip $skip): self
    {
        $new = clone $this;
        $new->set($this->count(), $skip);

        return $new;
    }

    /**
     * Get all action skips as an array.
     *
     * @return ActionSkip[]
     */
    public function toArray(): array
    {
        return parent::toArray();
    }

    /**
     * Override set to ensure type safety.
     *
     * @param int $key
     *
     * @throws InvalidArgumentException if value is not an ActionSkip
     */
    public function set($key, $value): void
    {
        if (!$value instanceof ActionSkip) {
            throw new InvalidArgumentException('Value must be an instance of ActionSkip');
        }

        parent::set($key, $value);
    }

    /**
     * Override offsetSet to ensure type safety.
     *
     * @param int|string|null $offset
     *
     * @throws InvalidArgumentException if value is not an ActionSkip
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!$value instanceof ActionSkip) {
            throw new InvalidArgumentException('Value must be an instance of ActionSkip');
        }

        parent::offsetSet($offset, $value);
    }
}
