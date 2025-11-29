<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Action\Action;

interface ActionFactory
{
    public function fromClassName(string $className): Action;
}
