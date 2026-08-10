<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit\Locking;

use CoverGenius\StateFlow\Locking\LockStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LockStrategy enum
 */
class LockStrategyTest extends TestCase
{
    public function testNoneCaseExists(): void
    {
        $this->assertInstanceOf(LockStrategy::class, LockStrategy::NONE);
    }

    public function testFailFastCaseExists(): void
    {
        $this->assertInstanceOf(LockStrategy::class, LockStrategy::FAIL_FAST);
    }

    public function testWaitCaseExists(): void
    {
        $this->assertInstanceOf(LockStrategy::class, LockStrategy::WAIT);
    }

    public function testSkipCaseExists(): void
    {
        $this->assertInstanceOf(LockStrategy::class, LockStrategy::SKIP);
    }

    public function testAllCasesAreDistinct(): void
    {
        $cases = LockStrategy::cases();

        $this->assertCount(4, $cases);
        $this->assertContains(LockStrategy::NONE, $cases);
        $this->assertContains(LockStrategy::FAIL_FAST, $cases);
        $this->assertContains(LockStrategy::WAIT, $cases);
        $this->assertContains(LockStrategy::SKIP, $cases);
    }
}
