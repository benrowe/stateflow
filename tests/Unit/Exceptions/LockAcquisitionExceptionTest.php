<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Exceptions;

use BenRowe\StateFlow\Exceptions\LockAcquisitionException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LockAcquisitionException
 */
class LockAcquisitionExceptionTest extends TestCase
{
    public function testCanBeInstantiated(): void
    {
        $exception = new LockAcquisitionException('Test message');

        $this->assertInstanceOf(LockAcquisitionException::class, $exception);
        $this->assertSame('Test message', $exception->getMessage());
    }

    public function testExtendsRuntimeException(): void
    {
        $exception = new LockAcquisitionException();

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testCanHaveCode(): void
    {
        $exception = new LockAcquisitionException('Test', 123);

        $this->assertSame(123, $exception->getCode());
    }

    public function testCanHavePreviousException(): void
    {
        $previous = new \Exception('Previous exception');
        $exception = new LockAcquisitionException('Test', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}
