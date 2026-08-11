<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit\Exceptions;

use CoverGenius\StateFlow\Exceptions\LockLostException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LockLostException
 */
class LockLostExceptionTest extends TestCase
{
    public function testCanBeInstantiated(): void
    {
        $exception = new LockLostException('Test message');

        $this->assertInstanceOf(LockLostException::class, $exception);
        $this->assertSame('Test message', $exception->getMessage());
    }

    public function testExtendsRuntimeException(): void
    {
        $exception = new LockLostException();

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testCanHaveCode(): void
    {
        $exception = new LockLostException('Test', 123);

        $this->assertSame(123, $exception->getCode());
    }

    public function testCanHavePreviousException(): void
    {
        $previous = new \Exception('Previous exception');
        $exception = new LockLostException('Test', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}
