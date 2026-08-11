<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Events;

use CoverGenius\StateFlow\Action\Action;
use CoverGenius\StateFlow\Action\ActionContext;
use CoverGenius\StateFlow\Action\ActionResult;

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
