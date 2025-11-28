<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Action;

use BenRowe\StateFlow\State;

readonly class ActionResult
{
    public function __construct(
        public ExecutionState $executionState,
        public ?State $newState = null,
        public mixed $metadata = null,
    ) {
    }

    public static function continue(?State $newState = null): self
    {
        return new self(ExecutionState::CONTINUE, $newState);
    }

    public static function pause(?State $newState = null, mixed $metadata = null): self
    {
        return new self(ExecutionState::PAUSE, $newState, $metadata);
    }

    public static function stop(?State $newState = null, mixed $metadata = null): self
    {
        return new self(ExecutionState::STOP, $newState, $metadata);
    }
}
