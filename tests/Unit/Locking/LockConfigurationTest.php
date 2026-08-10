<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit\Locking;

use CoverGenius\StateFlow\Locking\LockConfiguration;
use CoverGenius\StateFlow\Locking\LockStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LockConfiguration
 */
class LockConfigurationTest extends TestCase
{
    public function testConstructorWithDefaults(): void
    {
        $config = new LockConfiguration();

        $this->assertSame(LockStrategy::FAIL_FAST, $config->strategy);
        $this->assertSame(30, $config->ttl);
        $this->assertSame(10, $config->waitTimeout);
        $this->assertSame(100, $config->retryInterval);
    }

    public function testConstructorWithCustomValues(): void
    {
        $config = new LockConfiguration(
            LockStrategy::WAIT,
            60,
            20,
            200
        );

        $this->assertSame(LockStrategy::WAIT, $config->strategy);
        $this->assertSame(60, $config->ttl);
        $this->assertSame(20, $config->waitTimeout);
        $this->assertSame(200, $config->retryInterval);
    }

    public function testConstructorWithSkipStrategy(): void
    {
        $config = new LockConfiguration(LockStrategy::SKIP);

        $this->assertSame(LockStrategy::SKIP, $config->strategy);
        $this->assertSame(30, $config->ttl);
    }

    public function testConstructorWithNoneStrategy(): void
    {
        $config = new LockConfiguration(LockStrategy::NONE);

        $this->assertSame(LockStrategy::NONE, $config->strategy);
    }
}
