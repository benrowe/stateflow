<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit;

use CoverGenius\StateFlow\ActionFactory;
use CoverGenius\StateFlow\ArrayDelta;
use CoverGenius\StateFlow\Configuration\Configuration;
use CoverGenius\StateFlow\GateFactory;
use CoverGenius\StateFlow\StateFactory;
use CoverGenius\StateFlow\Tests\Utils\Traits\CreatesTestStates;
use CoverGenius\StateFlow\TransitionContext;
use CoverGenius\StateFlow\TransitionContextSerializer;
use PHPUnit\Framework\TestCase;

final class TransitionContextSerializerTest extends TestCase
{
    use CreatesTestStates;

    public function testSerializeBasicContext(): void
    {
        $state = $this->createTestState(['id' => '123']);
        $delta = new ArrayDelta(['status' => 'processing']);
        $config = Configuration::fromArray([], []);
        $context = new TransitionContext($state, $delta, $config);

        $serializer = new TransitionContextSerializer();
        $serialized = $serializer->serialize($context);

        $this->assertJson($serialized);
    }

    public function testUnserializeBasicContext(): void
    {
        $state = $this->createTestState(['id' => '123']);
        $delta = new ArrayDelta(['status' => 'processing']);
        $config = Configuration::fromArray([], []);
        $originalContext = new TransitionContext($state, $delta, $config);

        $serializer = new TransitionContextSerializer();
        $serialized = $serializer->serialize($originalContext);

        $stateFactory = $this->createMock(StateFactory::class);
        $stateFactory->method('fromArray')->willReturn($state);

        $actionFactory = $this->createMock(ActionFactory::class);
        $gateFactory = $this->createMock(GateFactory::class);

        $restoredContext = $serializer->unserialize(
            $serialized,
            $stateFactory,
            $actionFactory,
            $gateFactory
        );

        $this->assertInstanceOf(TransitionContext::class, $restoredContext);
        $this->assertEquals($originalContext->getCurrentState()->toArray(), $restoredContext->getCurrentState()->toArray());
    }
}
