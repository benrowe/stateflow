<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit;

use BenRowe\StateFlow\ActionFactory;
use BenRowe\StateFlow\ArrayDelta;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\GateFactory;
use BenRowe\StateFlow\StateFactory;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestStates;
use BenRowe\StateFlow\TransitionContext;
use BenRowe\StateFlow\TransitionContextSerializer;
use PHPUnit\Framework\TestCase;

final class TransitionContextSerializerTest extends TestCase
{
    use CreatesTestStates;

    public function testSerializeBasicContext(): void
    {
        $state = $this->createTestState(['id' => '123']);
        $delta = new ArrayDelta(['status' => 'processing']);
        $config = new Configuration([], []);
        $context = new TransitionContext($state, $delta, $config);

        $serializer = new TransitionContextSerializer();
        $serialized = $serializer->serialize($context);

        $this->assertJson($serialized);
    }

    public function testUnserializeBasicContext(): void
    {
        $state = $this->createTestState(['id' => '123']);
        $delta = new ArrayDelta(['status' => 'processing']);
        $config = new Configuration([], []);
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
