<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Configuration;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Gate\Gate;

readonly class Configuration
{
    /**
     * @param Gate[] $transitionGates
     * @param Action[] $actions
     */
    public function __construct(private array $transitionGates, private array $actions)
    {

    }

    /**
     * @return Gate[]
     */
    public function getTransitionGates(): array
    {
        return $this->transitionGates;
    }

    /**
     * @return Action[]
     */
    public function getActions(): array
    {
        return $this->actions;
    }
}
