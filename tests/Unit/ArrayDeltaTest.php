<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit;

use CoverGenius\StateFlow\ArrayDelta;
use PHPUnit\Framework\TestCase;

class ArrayDeltaTest extends TestCase
{
    public function testHasReturnsTrueForExistingKey(): void
    {
        $delta = new ArrayDelta(['status' => 'active', 'count' => 0]);

        $this->assertTrue($delta->has('status'));
        $this->assertTrue($delta->has('count'));
    }

    public function testHasReturnsFalseForNonExistentKey(): void
    {
        $delta = new ArrayDelta(['status' => 'active']);

        $this->assertFalse($delta->has('missing'));
    }

    public function testHasReturnsTrueForNullValue(): void
    {
        $delta = new ArrayDelta(['value' => null]);

        $this->assertTrue($delta->has('value'));
    }

    public function testGetReturnsValueForExistingKey(): void
    {
        $delta = new ArrayDelta(['status' => 'active', 'count' => 42]);

        $this->assertEquals('active', $delta->get('status'));
        $this->assertEquals(42, $delta->get('count'));
    }

    public function testGetReturnsDefaultForNonExistentKey(): void
    {
        $delta = new ArrayDelta(['status' => 'active']);

        $this->assertNull($delta->get('missing'));
        $this->assertEquals('default', $delta->get('missing', 'default'));
        $this->assertEquals(100, $delta->get('missing', 100));
    }

    public function testGetReturnsNullValueCorrectly(): void
    {
        $delta = new ArrayDelta(['value' => null]);

        $this->assertNull($delta->get('value'));
        $this->assertNull($delta->get('value', 'default'));
    }

    public function testAsArrayReturnsOriginalArray(): void
    {
        $data = ['status' => 'active', 'count' => 42, 'tags' => ['foo', 'bar']];
        $delta = new ArrayDelta($data);

        $this->assertEquals($data, $delta->asArray());
    }

    public function testAsArrayReturnsEmptyArrayForEmptyDelta(): void
    {
        $delta = new ArrayDelta();

        $this->assertEquals([], $delta->asArray());
    }

    public function testJsonSerializeReturnsArray(): void
    {
        $data = ['status' => 'active', 'count' => 42];
        $delta = new ArrayDelta($data);

        $this->assertEquals($data, $delta->jsonSerialize());
    }

    public function testJsonEncodeWorks(): void
    {
        $data = ['status' => 'active', 'count' => 42];
        $delta = new ArrayDelta($data);

        $json = json_encode($delta);
        $this->assertEquals('{"status":"active","count":42}', $json);
    }

    public function testSupportsNestedArrays(): void
    {
        $delta = new ArrayDelta([
            'user' => [
                'name' => 'John',
                'email' => 'john@example.com',
            ],
        ]);

        $this->assertTrue($delta->has('user'));
        $this->assertEquals(['name' => 'John', 'email' => 'john@example.com'], $delta->get('user'));
    }

    public function testSupportsVariousDataTypes(): void
    {
        $delta = new ArrayDelta([
            'string' => 'value',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'array' => [1, 2, 3],
            'null' => null,
        ]);

        $this->assertEquals('value', $delta->get('string'));
        $this->assertEquals(42, $delta->get('int'));
        $this->assertEquals(3.14, $delta->get('float'));
        $this->assertTrue($delta->get('bool'));
        $this->assertEquals([1, 2, 3], $delta->get('array'));
        $this->assertNull($delta->get('null'));
    }
}
