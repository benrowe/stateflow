<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit\Events;

use CoverGenius\StateFlow\Action\Action;
use CoverGenius\StateFlow\Action\ActionContext;
use CoverGenius\StateFlow\ArrayDelta;
use CoverGenius\StateFlow\Events\ActionExecuting;
use CoverGenius\StateFlow\State;
use CoverGenius\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;

class ActionExecutingTest extends TestCase
{
    public function testGetters(): void
    {
        $action = $this->createMock(Action::class);
        $context = new ActionContext(
            $this->createMock(State::class),
            new ArrayDelta(['foo' => 'bar']),
            $this->createMock(TransitionContext::class)
        );

        $event = new ActionExecuting($action, $context);

        $this->assertSame($action, $event->action);
        $this->assertSame($context, $event->context);
    }
}
