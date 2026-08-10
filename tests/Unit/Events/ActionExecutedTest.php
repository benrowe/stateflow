<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit\Events;

use CoverGenius\StateFlow\Action\Action;
use CoverGenius\StateFlow\Action\ActionContext;
use CoverGenius\StateFlow\Action\ActionResult;
use CoverGenius\StateFlow\ArrayDelta;
use CoverGenius\StateFlow\Events\ActionExecuted;
use CoverGenius\StateFlow\State;
use CoverGenius\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;

class ActionExecutedTest extends TestCase
{
    public function testGetters(): void
    {
        $action = $this->createMock(Action::class);
        $context = new ActionContext(
            $this->createMock(State::class),
            new ArrayDelta(['foo' => 'bar']),
            $this->createMock(TransitionContext::class)
        );
        $result = ActionResult::continue();

        $event = new ActionExecuted($action, $context, $result);

        $this->assertSame($action, $event->action);
        $this->assertSame($context, $event->context);
        $this->assertSame($result, $event->result);
    }
}
