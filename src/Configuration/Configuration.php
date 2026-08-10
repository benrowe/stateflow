<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Configuration;

use CoverGenius\StateFlow\Action\Action;
use CoverGenius\StateFlow\Action\ActionCollection;
use CoverGenius\StateFlow\Gate\Gate;
use CoverGenius\StateFlow\Gate\GateCollection;

readonly class Configuration
{
    public function __construct(public GateCollection $transitionGates, public ActionCollection $actions) {}

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
