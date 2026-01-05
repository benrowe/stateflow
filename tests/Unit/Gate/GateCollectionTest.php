<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Gate;

use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateCollection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class GateCollectionTest extends TestCase
{
    public function testConstructorWithVariadicGates(): void
    {
        $gate1 = $this->createMock(Gate::class);
        $gate2 = $this->createMock(Gate::class);
        $gate3 = $this->createMock(Gate::class);

        $collection = new GateCollection($gate1, $gate2, $gate3);

        $this->assertCount(3, $collection);
        $this->assertSame([$gate1, $gate2, $gate3], $collection->toArray());
    }

    public function testConstructorWithNoGates(): void
    {
        $collection = new GateCollection();

        $this->assertCount(0, $collection);
        $this->assertSame([], $collection->toArray());
    }

    public function testEmptyStaticFactory(): void
    {
        $collection = GateCollection::empty();

        $this->assertInstanceOf(GateCollection::class, $collection);
        $this->assertCount(0, $collection);
        $this->assertSame([], $collection->toArray());
    }

    public function testFromArrayStaticFactory(): void
    {
        $gate1 = $this->createMock(Gate::class);
        $gate2 = $this->createMock(Gate::class);

        $collection = GateCollection::fromArray([$gate1, $gate2]);

        $this->assertInstanceOf(GateCollection::class, $collection);
        $this->assertCount(2, $collection);
        $this->assertSame([$gate1, $gate2], $collection->toArray());
    }

    public function testFromArrayWithEmptyArray(): void
    {
        $collection = GateCollection::fromArray([]);

        $this->assertCount(0, $collection);
    }

    public function testWithReturnsNewInstance(): void
    {
        $gate1 = $this->createMock(Gate::class);
        $gate2 = $this->createMock(Gate::class);

        $original = new GateCollection($gate1);
        $new = $original->with($gate2);

        // Original should be unchanged
        $this->assertCount(1, $original);
        $this->assertSame([$gate1], $original->toArray());

        // New collection should have both
        $this->assertCount(2, $new);
        $this->assertSame([$gate1, $gate2], $new->toArray());

        // They should be different instances
        $this->assertNotSame($original, $new);
    }

    public function testToArray(): void
    {
        $gate1 = $this->createMock(Gate::class);
        $gate2 = $this->createMock(Gate::class);

        $collection = new GateCollection($gate1, $gate2);
        $array = $collection->toArray();

        $this->assertCount(2, $array);
        $this->assertSame($gate1, $array[0]);
        $this->assertSame($gate2, $array[1]);
    }

    public function testCountable(): void
    {
        $gate1 = $this->createMock(Gate::class);
        $gate2 = $this->createMock(Gate::class);
        $gate3 = $this->createMock(Gate::class);

        $collection = new GateCollection($gate1, $gate2, $gate3);

        $this->assertCount(3, $collection);
        $this->assertSame(3, $collection->count());
    }

    public function testIterable(): void
    {
        $gate1 = $this->createMock(Gate::class);
        $gate2 = $this->createMock(Gate::class);

        $collection = new GateCollection($gate1, $gate2);

        $gates = [];
        foreach ($collection as $gate) {
            $gates[] = $gate;
        }

        $this->assertSame([$gate1, $gate2], $gates);
    }

    public function testSetThrowsExceptionForWrongType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Value must be an instance of Gate');

        $collection = new GateCollection();
        // @phpstan-ignore argument.type
        $collection->set(0, 'not a gate');
    }

    public function testOffsetSetThrowsExceptionForWrongType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Value must be an instance of Gate');

        $collection = new GateCollection();
        // @phpstan-ignore offsetAssign.valueType
        $collection[0] = 'not a gate';
    }

    public function testChainedWiths(): void
    {
        $gate1 = $this->createMock(Gate::class);
        $gate2 = $this->createMock(Gate::class);
        $gate3 = $this->createMock(Gate::class);

        $collection = GateCollection::empty()
            ->with($gate1)
            ->with($gate2)
            ->with($gate3);

        $this->assertCount(3, $collection);
        $this->assertSame([$gate1, $gate2, $gate3], $collection->toArray());
    }

    public function testCanSetWithArray(): void
    {
        $collection = new GateCollection();
        $gate = $this->createMock(Gate::class);
        $collection[] = $gate;
        $this->assertCount(1, $collection);
        $this->assertSame([$gate], $collection->toArray());
    }
}
