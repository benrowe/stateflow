<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit\Events;

use CoverGenius\StateFlow\Action\Action;
use CoverGenius\StateFlow\Events\ActionSkipped;
use CoverGenius\StateFlow\Gate\GateResult;
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
