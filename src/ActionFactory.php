<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow;

use CoverGenius\StateFlow\Action\Action;

interface ActionFactory
{
    public function fromClassName(string $className): Action;
}
