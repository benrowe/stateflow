<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Events;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Events\ActionSkipped;
use BenRowe\StateFlow\Gate\GateResult;
use PHPUnit\Framework\TestCase;

class ActionSkippedTest extends TestCase
{
    public function testGetters(): void
    {
        $action = $this->createMock(Action::class);
        $gateResult = GateResult::DENY;

        $event = new ActionSkipped($action, $gateResult);

        $this->assertSame($action, $event->action);
        $this->assertSame($gateResult, $event->gateResult);
    }
}
