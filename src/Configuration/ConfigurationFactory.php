<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Configuration;

use CoverGenius\StateFlow\Action\Action;
use CoverGenius\StateFlow\Action\ActionCollection;
use CoverGenius\StateFlow\Exceptions\InvalidConfigurationException;
use CoverGenius\StateFlow\Gate\Gate;
use CoverGenius\StateFlow\Gate\GateCollection;

class ConfigurationFactory
{
    /**
     * @param Gate[] $transitionGates
     * @param Action[] $actions
     */
    public function makeFromArray(array $transitionGates, array $actions): Configuration
    {
        return new Configuration(
            $this->createGateCollection($transitionGates),
            $this->createActionCollection($actions)
        );
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
     *
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
     *
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
