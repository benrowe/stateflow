<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Events;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Events\ActionExecuting;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;

class ActionExecutingTest extends TestCase
{
    public function testGetters(): void
    {
        $action = $this->createMock(Action::class);
        $context = new ActionContext(
            $this->createMock(State::class),
            ['foo' => 'bar'],
            new TransitionContext()
        );

        $event = new ActionExecuting($action, $context);

        $this->assertSame($action, $event->action);
        $this->assertSame($context, $event->context);
    }
}
