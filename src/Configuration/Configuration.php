<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Configuration;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Exceptions\InvalidConfigurationException;
use BenRowe\StateFlow\Gate\Gate;

readonly class Configuration
{
    /**
     * @param Gate[] $transitionGates
     * @param Action[] $actions
     */
    public function __construct(private array $transitionGates, private array $actions)
    {
        $this->validateGates();
        $this->validateActions();
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

    private function validateGates(): void
    {
        foreach ($this->transitionGates as $index => $gate) {
            if (!$gate instanceof Gate) {
                throw new InvalidConfigurationException(
                    sprintf(
                        'Gate at index %d must implement %s, %s given',
                        $index,
                        Gate::class,
                        get_debug_type($gate)
                    )
                );
            }
        }
    }

    private function validateActions(): void
    {
        foreach ($this->actions as $index => $action) {
            if (!$action instanceof Action) {
                throw new InvalidConfigurationException(
                    sprintf(
                        'Action at index %d must implement %s, %s given',
                        $index,
                        Action::class,
                        get_debug_type($action)
                    )
                );
            }
        }
    }
}
