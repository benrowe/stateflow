<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit;

use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\GateEvaluation;
use BenRowe\StateFlow\GateEvaluationCollection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class GateEvaluationCollectionTest extends TestCase
{
    public function testConstructorWithVariadicEvaluations(): void
    {
        $gate1 = $this->createMock(Gate::class);
        $gate2 = $this->createMock(Gate::class);
        $gate3 = $this->createMock(Gate::class);

        $eval1 = new GateEvaluation($gate1, GateResult::ALLOW, false);
        $eval2 = new GateEvaluation($gate2, GateResult::DENY, true);
        $eval3 = new GateEvaluation($gate3, GateResult::SKIP_IDEMPOTENT, false);

        $collection = new GateEvaluationCollection($eval1, $eval2, $eval3);

        $this->assertCount(3, $collection);
        $this->assertSame([$eval1, $eval2, $eval3], $collection->toArray());
    }

    public function testConstructorWithNoEvaluations(): void
    {
        $collection = new GateEvaluationCollection();

        $this->assertCount(0, $collection);
        $this->assertSame([], $collection->toArray());
    }

    public function testEmptyStaticFactory(): void
    {
        $collection = GateEvaluationCollection::empty();

        $this->assertInstanceOf(GateEvaluationCollection::class, $collection);
        $this->assertCount(0, $collection);
        $this->assertSame([], $collection->toArray());
    }

    public function testFromArrayStaticFactory(): void
    {
        $gate1 = $this->createMock(Gate::class);
        $gate2 = $this->createMock(Gate::class);

        $eval1 = new GateEvaluation($gate1, GateResult::ALLOW, false);
        $eval2 = new GateEvaluation($gate2, GateResult::DENY, true);

        $collection = GateEvaluationCollection::fromArray([$eval1, $eval2]);

        $this->assertInstanceOf(GateEvaluationCollection::class, $collection);
        $this->assertCount(2, $collection);
        $this->assertSame([$eval1, $eval2], $collection->toArray());
    }

    public function testFromArrayWithEmptyArray(): void
    {
        $collection = GateEvaluationCollection::fromArray([]);

        $this->assertCount(0, $collection);
    }

    public function testWithReturnsNewInstance(): void
    {
        $gate1 = $this->createMock(Gate::class);
        $gate2 = $this->createMock(Gate::class);

        $eval1 = new GateEvaluation($gate1, GateResult::ALLOW, false);
        $eval2 = new GateEvaluation($gate2, GateResult::DENY, true);

        $original = new GateEvaluationCollection($eval1);
        $new = $original->with($eval2);

        // Original should be unchanged
        $this->assertCount(1, $original);
        $this->assertSame([$eval1], $original->toArray());

        // New collection should have both
        $this->assertCount(2, $new);
        $this->assertSame([$eval1, $eval2], $new->toArray());

        // They should be different instances
        $this->assertNotSame($original, $new);
    }

    public function testToArray(): void
    {
        $gate1 = $this->createMock(Gate::class);
        $gate2 = $this->createMock(Gate::class);

        $eval1 = new GateEvaluation($gate1, GateResult::ALLOW, false);
        $eval2 = new GateEvaluation($gate2, GateResult::DENY, true);

        $collection = new GateEvaluationCollection($eval1, $eval2);
        $array = $collection->toArray();

        $this->assertCount(2, $array);
        $this->assertSame($eval1, $array[0]);
        $this->assertSame($eval2, $array[1]);
    }

    public function testCountable(): void
    {
        $gate1 = $this->createMock(Gate::class);
        $gate2 = $this->createMock(Gate::class);
        $gate3 = $this->createMock(Gate::class);

        $eval1 = new GateEvaluation($gate1, GateResult::ALLOW, false);
        $eval2 = new GateEvaluation($gate2, GateResult::DENY, true);
        $eval3 = new GateEvaluation($gate3, GateResult::SKIP_IDEMPOTENT, false);

        $collection = new GateEvaluationCollection($eval1, $eval2, $eval3);

        $this->assertCount(3, $collection);
        $this->assertSame(3, $collection->count());
    }

    public function testIterable(): void
    {
        $gate1 = $this->createMock(Gate::class);
        $gate2 = $this->createMock(Gate::class);

        $eval1 = new GateEvaluation($gate1, GateResult::ALLOW, false);
        $eval2 = new GateEvaluation($gate2, GateResult::DENY, true);

        $collection = new GateEvaluationCollection($eval1, $eval2);

        $evaluations = [];
        foreach ($collection as $evaluation) {
            $evaluations[] = $evaluation;
        }

        $this->assertSame([$eval1, $eval2], $evaluations);
    }

    public function testSetThrowsExceptionForWrongType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Value must be an instance of GateEvaluation');

        $collection = new GateEvaluationCollection();
        // @phpstan-ignore argument.type
        $collection->set(0, 'not a gate evaluation');
    }

    public function testOffsetSetThrowsExceptionForWrongType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Value must be an instance of GateEvaluation');

        $collection = new GateEvaluationCollection();
        // @phpstan-ignore offsetAssign.valueType
        $collection[0] = 'not a gate evaluation';
    }

    public function testChainedWiths(): void
    {
        $gate1 = $this->createMock(Gate::class);
        $gate2 = $this->createMock(Gate::class);
        $gate3 = $this->createMock(Gate::class);

        $eval1 = new GateEvaluation($gate1, GateResult::ALLOW, false);
        $eval2 = new GateEvaluation($gate2, GateResult::DENY, true);
        $eval3 = new GateEvaluation($gate3, GateResult::SKIP_IDEMPOTENT, false);

        $collection = GateEvaluationCollection::empty()
            ->with($eval1)
            ->with($eval2)
            ->with($eval3);

        $this->assertCount(3, $collection);
        $this->assertSame([$eval1, $eval2, $eval3], $collection->toArray());
    }

    public function testCanSetWithArray(): void
    {
        $collection = new GateEvaluationCollection();
        $gate1 = $this->createMock(Gate::class);
        $eval1 = new GateEvaluation($gate1, GateResult::ALLOW, false);
        $collection[] = $eval1;
        $this->assertCount(1, $collection);
        $this->assertSame([$eval1], $collection->toArray());
    }
}
