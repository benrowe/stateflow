<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Action;

use Doctrine\Common\Collections\ArrayCollection;
use InvalidArgumentException;

/**
 * Immutable typed collection for Action objects.
 *
 * Provides runtime type safety for Action collections with a readonly, immutable interface.
 *
 * @extends ArrayCollection<int, Action>
 */
final class ActionCollection extends ArrayCollection
{
    /**
     * Create a new immutable action collection.
     */
    public function __construct(Action ...$actions)
    {
        parent::__construct(array_values($actions));
    }

    /**
     * Create an empty collection.
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Create a collection from an array of actions.
     *
     * @param Action[] $actions
     */
    public static function fromArray(array $actions): self
    {
        return new self(...$actions);
    }

    /**
     * Add an action and return a new collection (immutable).
     */
    public function with(Action $action): self
    {
        $new = clone $this;
        $new->set($this->count(), $action);

        return $new;
    }

    /**
     * Get all actions as an array.
     *
     * @return Action[]
     */
    public function toArray(): array
    {
        return parent::toArray();
    }

    /**
     * Override set to ensure type safety.
     *
     * @param int $key
     * @throws InvalidArgumentException if value is not an Action
     */
    public function set($key, $value): void
    {
        if (!$value instanceof Action) {
            throw new InvalidArgumentException('Value must be an instance of Action');
        }

        parent::set($key, $value);
    }

    /**
     * Override offsetSet to ensure type safety.
     *
     * @param int|string|null $offset
     * @throws InvalidArgumentException if value is not an Action
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!$value instanceof Action) {
            throw new InvalidArgumentException('Value must be an instance of Action');
        }

        parent::offsetSet($offset, $value);
    }
}
