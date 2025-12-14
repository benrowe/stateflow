<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use Doctrine\Common\Collections\ArrayCollection;
use InvalidArgumentException;

/**
 * Immutable typed collection for GateEvaluation objects.
 *
 * Provides runtime type safety for GateEvaluation collections with a readonly, immutable interface.
 *
 * @extends ArrayCollection<int, GateEvaluation>
 */
final class GateEvaluationCollection extends ArrayCollection
{
    /**
     * Create a new immutable gate evaluation collection.
     *
     * @param GateEvaluation ...$evaluations
     */
    public function __construct(GateEvaluation ...$evaluations)
    {
        parent::__construct(array_values($evaluations));
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
     * Create a collection from an array of gate evaluations.
     *
     * @param GateEvaluation[] $evaluations
     * @return self
     */
    public static function fromArray(array $evaluations): self
    {
        return new self(...$evaluations);
    }

    /**
     * Add a gate evaluation and return a new collection (immutable).
     *
     * @param GateEvaluation $evaluation
     * @return self
     */
    public function with(GateEvaluation $evaluation): self
    {
        $new = clone $this;
        $new->set($this->count(), $evaluation);

        return $new;
    }

    /**
     * Get all gate evaluations as an array.
     *
     * @return GateEvaluation[]
     */
    public function toArray(): array
    {
        return parent::toArray();
    }

    /**
     * Override set to ensure type safety.
     *
     * @param int $key
     * @param mixed $value
     * @throws InvalidArgumentException if value is not a GateEvaluation
     */
    public function set($key, $value): void
    {
        if (!$value instanceof GateEvaluation) {
            throw new InvalidArgumentException('Value must be an instance of GateEvaluation');
        }

        parent::set($key, $value);
    }

    /**
     * Override offsetSet to ensure type safety.
     *
     * @param int|string|null $offset
     * @param mixed $value
     * @throws InvalidArgumentException if value is not a GateEvaluation
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!$value instanceof GateEvaluation) {
            throw new InvalidArgumentException('Value must be an instance of GateEvaluation');
        }

        parent::offsetSet($offset, $value);
    }
}
