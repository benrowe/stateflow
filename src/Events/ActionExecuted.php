<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Events;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ActionResult;

class ActionExecuted extends Event
{
    public function __construct(
        public Action $action,
        public ActionContext $context,
        public ActionResult $result,
    ) {
        parent::__construct();
    }
}
