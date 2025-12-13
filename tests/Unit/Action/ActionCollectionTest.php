<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Action;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionCollection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ActionCollectionTest extends TestCase
{
    public function testConstructorWithVariadicActions(): void
    {
        $action1 = $this->createMock(Action::class);
        $action2 = $this->createMock(Action::class);
        $action3 = $this->createMock(Action::class);

        $collection = new ActionCollection($action1, $action2, $action3);

        $this->assertCount(3, $collection);
        $this->assertSame([$action1, $action2, $action3], $collection->toArray());
    }

    public function testConstructorWithNoActions(): void
    {
        $collection = new ActionCollection();

        $this->assertCount(0, $collection);
        $this->assertSame([], $collection->toArray());
    }

    public function testEmptyStaticFactory(): void
    {
        $collection = ActionCollection::empty();

        $this->assertInstanceOf(ActionCollection::class, $collection);
        $this->assertCount(0, $collection);
        $this->assertSame([], $collection->toArray());
    }

    public function testFromArrayStaticFactory(): void
    {
        $action1 = $this->createMock(Action::class);
        $action2 = $this->createMock(Action::class);

        $collection = ActionCollection::fromArray([$action1, $action2]);

        $this->assertInstanceOf(ActionCollection::class, $collection);
        $this->assertCount(2, $collection);
        $this->assertSame([$action1, $action2], $collection->toArray());
    }

    public function testFromArrayWithEmptyArray(): void
    {
        $collection = ActionCollection::fromArray([]);

        $this->assertCount(0, $collection);
    }

    public function testWithReturnsNewInstance(): void
    {
        $action1 = $this->createMock(Action::class);
        $action2 = $this->createMock(Action::class);

        $original = new ActionCollection($action1);
        $new = $original->with($action2);

        // Original should be unchanged
        $this->assertCount(1, $original);
        $this->assertSame([$action1], $original->toArray());

        // New collection should have both
        $this->assertCount(2, $new);
        $this->assertSame([$action1, $action2], $new->toArray());

        // They should be different instances
        $this->assertNotSame($original, $new);
    }

    public function testToArray(): void
    {
        $action1 = $this->createMock(Action::class);
        $action2 = $this->createMock(Action::class);

        $collection = new ActionCollection($action1, $action2);
        $array = $collection->toArray();

        $this->assertCount(2, $array);
        $this->assertSame($action1, $array[0]);
        $this->assertSame($action2, $array[1]);
    }

    public function testCountable(): void
    {
        $action1 = $this->createMock(Action::class);
        $action2 = $this->createMock(Action::class);
        $action3 = $this->createMock(Action::class);

        $collection = new ActionCollection($action1, $action2, $action3);

        $this->assertCount(3, $collection);
        $this->assertSame(3, $collection->count());
    }

    public function testIterable(): void
    {
        $action1 = $this->createMock(Action::class);
        $action2 = $this->createMock(Action::class);

        $collection = new ActionCollection($action1, $action2);

        $actions = [];
        foreach ($collection as $action) {
            $actions[] = $action;
        }

        $this->assertSame([$action1, $action2], $actions);
    }

    public function testSetThrowsExceptionForWrongType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Value must be an instance of Action');

        $collection = new ActionCollection();
        $collection->set(0, 'not an action');
    }

    public function testOffsetSetThrowsExceptionForWrongType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Value must be an instance of Action');

        $collection = new ActionCollection();
        $collection[0] = 'not an action';
    }

    public function testChainedWiths(): void
    {
        $action1 = $this->createMock(Action::class);
        $action2 = $this->createMock(Action::class);
        $action3 = $this->createMock(Action::class);

        $collection = ActionCollection::empty()
            ->with($action1)
            ->with($action2)
            ->with($action3);

        $this->assertCount(3, $collection);
        $this->assertSame([$action1, $action2, $action3], $collection->toArray());
    }

    public function testCanSetWithArray(): void
    {
        $collection = new ActionCollection();
        $action = $this->createMock(Action::class);
        $collection[] = $action;
        $this->assertCount(1, $collection);
        $this->assertSame([$action], $collection->toArray());
    }
}
