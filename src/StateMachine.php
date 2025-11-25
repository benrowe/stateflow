<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

/**
 * StateMachine - A flexible state machine implementation
 */
class StateMachine
{
    private string $currentState;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $transitions = [];

    public function __construct(string $initialState)
    {
        $this->currentState = $initialState;
    }

    public function getCurrentState(): string
    {
        return $this->currentState;
    }

    /**
     * Add a transition from one state to another
     *
     * @param string $from The source state
     * @param string $to The target state
     * @param string|null $event The event that triggers this transition
     */
    public function addTransition(string $from, string $to, ?string $event = null): self
    {
        $key = $event ?? 'default';

        if (!isset($this->transitions[$from])) {
            $this->transitions[$from] = [];
        }

        $this->transitions[$from][$key] = $to;

        return $this;
    }

    /**
     * Transition to a new state
     *
     * @param string|null $event The event triggering the transition
     * @throws \RuntimeException If the transition is not allowed
     */
    public function transition(?string $event = null): self
    {
        $key = $event ?? 'default';

        if (!isset($this->transitions[$this->currentState][$key])) {
            throw new \RuntimeException(
                sprintf(
                    'No transition defined from state "%s" for event "%s"',
                    $this->currentState,
                    $key
                )
            );
        }

        $this->currentState = $this->transitions[$this->currentState][$key];

        return $this;
    }

    /**
     * Check if a transition is possible
     *
     * @param string|null $event The event to check
     */
    public function canTransition(?string $event = null): bool
    {
        $key = $event ?? 'default';

        return isset($this->transitions[$this->currentState][$key]);
    }
}
