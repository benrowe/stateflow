<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Action;

use Doctrine\Common\Collections\ArrayCollection;
use InvalidArgumentException;

/**
 * Immutable typed collection for ActionResult objects.
 *
 * Provides runtime type safety for ActionResult collections with a readonly, immutable interface.
 *
 * @extends ArrayCollection<int, ActionResult>
 */
final class ActionResultCollection extends ArrayCollection
{
    /**
     * Create a new immutable action result collection.
     */
    public function __construct(ActionResult ...$results)
    {
        parent::__construct(array_values($results));
    }

    /**
     * Create an empty collection.
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Create a collection from an array of action results.
     *
     * @param ActionResult[] $results
     */
    public static function fromArray(array $results): self
    {
        return new self(...$results);
    }

    /**
     * Add an action result and return a new collection (immutable).
     */
    public function with(ActionResult $result): self
    {
        $new = clone $this;
        $new->set($this->count(), $result);

        return $new;
    }

    /**
     * Get all action results as an array.
     *
     * @return ActionResult[]
     */
    public function toArray(): array
    {
        return parent::toArray();
    }

    /**
     * Override set to ensure type safety.
     *
     * @param int $key
     * @throws InvalidArgumentException if value is not an ActionResult
     */
    public function set($key, $value): void
    {
        if (!$value instanceof ActionResult) {
            throw new InvalidArgumentException('Value must be an instance of ActionResult');
        }

        parent::set($key, $value);
    }

    /**
     * Override offsetSet to ensure type safety.
     *
     * @param int|string|null $offset
     * @throws InvalidArgumentException if value is not an ActionResult
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!$value instanceof ActionResult) {
            throw new InvalidArgumentException('Value must be an instance of ActionResult');
        }

        parent::offsetSet($offset, $value);
    }
}
