<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit\Action;

use CoverGenius\StateFlow\Action\ActionResult;
use CoverGenius\StateFlow\Action\ActionResultCollection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ActionResultCollectionTest extends TestCase
{
    public function testConstructorWithBadActionResult(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('All elements must be instances of ' . ActionResult::class);

        // @phpstan-ignore-next-line
        new ActionResultCollection([stdClass::class]);
    }

    public function testConstructorWithNoResults(): void
    {
        $collection = new ActionResultCollection();

        $this->assertCount(0, $collection);
        $this->assertSame([], $collection->toArray());
    }

    public function testEmptyStaticFactory(): void
    {
        $collection = ActionResultCollection::empty();

        $this->assertInstanceOf(ActionResultCollection::class, $collection);
        $this->assertCount(0, $collection);
        $this->assertSame([], $collection->toArray());
    }

    public function testFromArrayStaticFactory(): void
    {
        $result1 = ActionResult::continue();
        $result2 = ActionResult::pause();

        $collection = ActionResultCollection::fromArray([$result1, $result2]);

        $this->assertInstanceOf(ActionResultCollection::class, $collection);
        $this->assertCount(2, $collection);
        $this->assertSame([$result1, $result2], $collection->toArray());
    }

    public function testFromArrayWithEmptyArray(): void
    {
        $collection = ActionResultCollection::fromArray([]);

        $this->assertCount(0, $collection);
    }

    public function testWithReturnsNewInstance(): void
    {
        $result1 = ActionResult::continue();
        $result2 = ActionResult::pause();

        $original = new ActionResultCollection([$result1]);
        $new = $original->with($result2);

        // Original should be unchanged
        $this->assertCount(1, $original);
        $this->assertSame([$result1], $original->toArray());

        // New collection should have both
        $this->assertCount(2, $new);
        $this->assertSame([$result1, $result2], $new->toArray());

        // They should be different instances
        $this->assertNotSame($original, $new);
    }

    public function testToArray(): void
    {
        $result1 = ActionResult::continue();
        $result2 = ActionResult::pause();

        $collection = new ActionResultCollection([$result1, $result2]);
        $array = $collection->toArray();

        $this->assertCount(2, $array);
        $this->assertSame($result1, $array[0]);
        $this->assertSame($result2, $array[1]);
    }

    public function testCountable(): void
    {
        $result1 = ActionResult::continue();
        $result2 = ActionResult::pause();
        $result3 = ActionResult::stop();

        $collection = new ActionResultCollection([$result1, $result2, $result3]);

        $this->assertCount(3, $collection);
        $this->assertSame(3, $collection->count());
    }

    public function testIterable(): void
    {
        $result1 = ActionResult::continue();
        $result2 = ActionResult::pause();

        $collection = new ActionResultCollection([$result1, $result2]);

        $results = [];
        foreach ($collection as $result) {
            $results[] = $result;
        }

        $this->assertSame([$result1, $result2], $results);
    }

    public function testSetThrowsExceptionForWrongType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Value must be an instance of ActionResult');

        $collection = new ActionResultCollection();
        // @phpstan-ignore argument.type
        $collection->set(0, 'not an action result');
    }

    public function testOffsetSetThrowsExceptionForWrongType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Value must be an instance of ActionResult');

        $collection = new ActionResultCollection();
        // @phpstan-ignore offsetAssign.valueType
        $collection[0] = 'not an action result';
    }

    public function testChainedWiths(): void
    {
        $result1 = ActionResult::continue();
        $result2 = ActionResult::pause();
        $result3 = ActionResult::stop();

        $collection = ActionResultCollection::empty()
            ->with($result1)
            ->with($result2)
            ->with($result3);

        $this->assertCount(3, $collection);
        $this->assertSame([$result1, $result2, $result3], $collection->toArray());
    }

    public function testCanSetWithArray(): void
    {
        $collection = new ActionResultCollection();
        $result = ActionResult::continue();
        $collection[] = $result;
        $this->assertCount(1, $collection);
        $this->assertSame([$result], $collection->toArray());
    }
}
