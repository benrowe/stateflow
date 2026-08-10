<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Events;

use CoverGenius\StateFlow\Action\Action;
use CoverGenius\StateFlow\Action\ActionContext;

class ActionExecuting extends Event
{
    public function __construct(
        public Action $action,
        public ActionContext $context,
    ) {
        parent::__construct();
    }
}
