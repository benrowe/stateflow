<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Gate\Gate;

interface GateFactory
{
    /**
     * Reconstruct a Gate instance from its class name.
     */
    public function fromClassName(string $className): Gate;
}
