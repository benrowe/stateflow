<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Configuration;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionCollection;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateCollection;

readonly class Configuration
{
    public function __construct(public GateCollection $transitionGates, public ActionCollection $actions)
    {
    }

    /**
     * Alias for new ConfigurationFactory()->makeFromArray($transitionGates, $actions)
     *
     * @param Gate[] $transitionGates
     * @param Action[] $actions
     */
    public static function fromArray(array $transitionGates, array $actions): self
    {
        return (new ConfigurationFactory())->makeFromArray($transitionGates, $actions);
    }
}
