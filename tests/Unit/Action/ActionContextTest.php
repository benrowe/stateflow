<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit\Action;

use CoverGenius\StateFlow\Action\ActionContext;
use CoverGenius\StateFlow\Action\YieldResponse;
use CoverGenius\StateFlow\Delta;
use CoverGenius\StateFlow\State;
use CoverGenius\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;

class ActionContextTest extends TestCase
{
    public function testDefaultsToNoYieldResponse(): void
    {
        $context = new ActionContext(
            $this->createMock(State::class),
            $this->createMock(Delta::class),
            $this->createMock(TransitionContext::class),
        );

        $this->assertFalse($context->hasYieldResponse());
        $this->assertNull($context->yieldResponse());
    }

    public function testExposesYieldResponseWhenProvided(): void
    {
        $context = new ActionContext(
            $this->createMock(State::class),
            $this->createMock(Delta::class),
            $this->createMock(TransitionContext::class),
            new YieldResponse(['outcome' => 'approved']),
        );

        $this->assertTrue($context->hasYieldResponse());
        $this->assertSame(['outcome' => 'approved'], $context->yieldResponse());
    }

    public function testDistinguishesNullResponseDataFromNoResponse(): void
    {
        $context = new ActionContext(
            $this->createMock(State::class),
            $this->createMock(Delta::class),
            $this->createMock(TransitionContext::class),
            new YieldResponse(null),
        );

        $this->assertTrue($context->hasYieldResponse());
        $this->assertNull($context->yieldResponse());
    }
}
