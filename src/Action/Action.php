<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Action;

interface Action
{
    public function execute(ActionContext $context): ActionResult;
}
