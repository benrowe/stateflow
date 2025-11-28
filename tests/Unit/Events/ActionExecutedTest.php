<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Events;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Events\ActionExecuted;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;

class ActionExecutedTest extends TestCase
{
    public function testGetters(): void
    {
        $action = $this->createMock(Action::class);
        $context = new ActionContext(
            $this->createMock(State::class),
            ['foo' => 'bar'],
            new TransitionContext()
        );
        $result = ActionResult::continue();

        $event = new ActionExecuted($action, $context, $result);

        $this->assertSame($action, $event->action);
        $this->assertSame($context, $event->context);
        $this->assertSame($result, $event->result);
    }
}
