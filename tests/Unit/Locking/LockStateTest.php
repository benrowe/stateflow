<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Locking;

use BenRowe\StateFlow\Locking\LockState;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LockState
 */
class LockStateTest extends TestCase
{
    public function testIsLockedReturnsFalseWhenNoLockKey(): void
    {
        $lockState = new LockState();

        $this->assertFalse($lockState->isLocked());
    }

    public function testIsLockedReturnsFalseWhenNoAcquiredAt(): void
    {
        $lockState = new LockState('key:123');

        $this->assertFalse($lockState->isLocked());
    }

    public function testIsLockedReturnsTrueWhenBothPresent(): void
    {
        $lockState = new LockState('key:123', 1234567890.0, 30);

        $this->assertTrue($lockState->isLocked());
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $lockState = new LockState('key:123', 1234567890.0, 30);

        $array = $lockState->toArray();

        $this->assertSame([
            'lockKey' => 'key:123',
            'acquiredAt' => 1234567890.0,
            'ttl' => 30,
        ], $array);
    }

    public function testToArrayHandlesNulls(): void
    {
        $lockState = new LockState();

        $array = $lockState->toArray();

        $this->assertSame([
            'lockKey' => null,
            'acquiredAt' => null,
            'ttl' => null,
        ], $array);
    }

    public function testFromArrayCreatesCorrectInstance(): void
    {
        $data = [
            'lockKey' => 'key:456',
            'acquiredAt' => 9876543210.0,
            'ttl' => 60,
        ];

        $lockState = LockState::fromArray($data);

        $this->assertSame('key:456', $lockState->lockKey);
        $this->assertSame(9876543210.0, $lockState->acquiredAt);
        $this->assertSame(60, $lockState->ttl);
        $this->assertTrue($lockState->isLocked());
    }

    public function testFromArrayHandlesMissingKeys(): void
    {
        $data = [];

        $lockState = LockState::fromArray($data);

        $this->assertNull($lockState->lockKey);
        $this->assertNull($lockState->acquiredAt);
        $this->assertNull($lockState->ttl);
        $this->assertFalse($lockState->isLocked());
    }

    public function testConstructorSetsProperties(): void
    {
        $lockState = new LockState('key:789', 1111111111.0, 45);

        $this->assertSame('key:789', $lockState->lockKey);
        $this->assertSame(1111111111.0, $lockState->acquiredAt);
        $this->assertSame(45, $lockState->ttl);
    }
}
