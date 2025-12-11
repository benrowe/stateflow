<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Configuration;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionCollection;
use BenRowe\StateFlow\Exceptions\InvalidConfigurationException;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateCollection;

readonly class Configuration
{
    private GateCollection $transitionGates;

    private ActionCollection $actions;

    /**
     * @param Gate[]|GateCollection $transitionGates
     * @param Action[]|ActionCollection $actions
     */
    public function __construct(
        GateCollection|array $transitionGates,
        ActionCollection|array $actions
    ) {
        $this->transitionGates = $transitionGates instanceof GateCollection
            ? $transitionGates
            : $this->createGateCollection($transitionGates);

        $this->actions = $actions instanceof ActionCollection
            ? $actions
            : $this->createActionCollection($actions);
    }

    public function getTransitionGates(): GateCollection
    {
        return $this->transitionGates;
    }

    public function getActions(): ActionCollection
    {
        return $this->actions;
    }

    /**
     * @param Gate[] $gates
     */
    private function createGateCollection(array $gates): GateCollection
    {
        $this->validateGates($gates);

        return GateCollection::fromArray($gates);
    }

    /**
     * @param Action[] $actions
     */
    private function createActionCollection(array $actions): ActionCollection
    {
        $this->validateActions($actions);

        return ActionCollection::fromArray($actions);
    }

    /**
     * @param array<mixed> $gates
     * @phpstan-assert Gate[] $gates
     */
    private function validateGates(array $gates): void
    {
        foreach ($gates as $index => $gate) {
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

    /**
     * @param array<mixed> $actions
     * @phpstan-assert Action[] $actions
     */
    private function validateActions(array $actions): void
    {
        foreach ($actions as $index => $action) {
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
