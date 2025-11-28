<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Events;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionContext;

class ActionExecuting extends Event
{
    public function __construct(
        public Action $action,
        public ActionContext $context,
    ) {
        parent::__construct();
    }
}
