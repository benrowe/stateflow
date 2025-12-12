<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit;

use BenRowe\StateFlow\ExecutionStatus;
use PHPUnit\Framework\TestCase;

class ExecutionStatusTest extends TestCase
{
    public function testDefaultState(): void
    {
        $status = new ExecutionStatus();

        $this->assertFalse($status->isCompleted());
        $this->assertFalse($status->isPaused());
        $this->assertFalse($status->isStopped());
        $this->assertFalse($status->wasSkippedDueToLock());
        $this->assertNull($status->getMetadata());
    }

    public function testMarkCompleted(): void
    {
        $status = new ExecutionStatus();
        $status->markCompleted();

        $this->assertTrue($status->isCompleted());
        $this->assertFalse($status->isPaused());
        $this->assertFalse($status->isStopped());
    }

    public function testMarkPausedWithoutMetadata(): void
    {
        $status = new ExecutionStatus();
        $status->markPaused();

        $this->assertFalse($status->isCompleted());
        $this->assertTrue($status->isPaused());
        $this->assertFalse($status->isStopped());
        $this->assertNull($status->getMetadata());
    }

    public function testMarkPausedWithMetadata(): void
    {
        $status = new ExecutionStatus();
        $metadata = ['reason' => 'waiting for approval', 'approver' => 'admin@example.com'];

        $status->markPaused($metadata);

        $this->assertTrue($status->isPaused());
        $this->assertSame($metadata, $status->getMetadata());
    }

    public function testMarkStoppedWithoutMetadata(): void
    {
        $status = new ExecutionStatus();
        $status->markStopped();

        $this->assertFalse($status->isCompleted());
        $this->assertFalse($status->isPaused());
        $this->assertTrue($status->isStopped());
        $this->assertNull($status->getMetadata());
    }

    public function testMarkStoppedWithMetadata(): void
    {
        $status = new ExecutionStatus();
        $metadata = ['error' => 'Payment failed', 'code' => 'CARD_DECLINED'];

        $status->markStopped($metadata);

        $this->assertTrue($status->isStopped());
        $this->assertSame($metadata, $status->getMetadata());
    }

    public function testMarkSkippedDueToLock(): void
    {
        $status = new ExecutionStatus();
        $status->markSkippedDueToLock();

        $this->assertTrue($status->wasSkippedDueToLock());
    }

    public function testStatusCanBeChangedMultipleTimes(): void
    {
        $status = new ExecutionStatus();

        // Mark as paused
        $status->markPaused(['step' => 1]);
        $this->assertTrue($status->isPaused());
        $this->assertSame(['step' => 1], $status->getMetadata());

        // Change to completed
        $status->markCompleted();
        $this->assertTrue($status->isCompleted());
        $this->assertFalse($status->isPaused());
        $this->assertNull($status->getMetadata(), 'Metadata should be cleared when marking as completed');

        // Change to stopped with different metadata
        $status->markStopped(['step' => 2]);
        $this->assertTrue($status->isStopped());
        $this->assertFalse($status->isCompleted());
        $this->assertSame(['step' => 2], $status->getMetadata());
    }

    public function testMetadataIsReplacedWhenStatusChanges(): void
    {
        $status = new ExecutionStatus();

        $status->markPaused(['first' => 'metadata']);
        $this->assertSame(['first' => 'metadata'], $status->getMetadata());

        $status->markStopped(['second' => 'metadata']);
        $this->assertSame(['second' => 'metadata'], $status->getMetadata());
    }

    public function testClearPauseStatusWhenPaused(): void
    {
        $status = new ExecutionStatus();

        $status->markPaused(['reason' => 'test']);
        $this->assertTrue($status->isPaused());

        $status->clearPauseStatus();

        $this->assertFalse($status->isPaused());
        $this->assertFalse($status->isCompleted());
        $this->assertFalse($status->isStopped());
        $this->assertNull($status->getMetadata(), 'Metadata should be cleared when clearing pause status');
    }

    public function testClearPauseStatusWhenNotPausedDoesNothing(): void
    {
        $status = new ExecutionStatus();

        $status->markCompleted();
        $this->assertTrue($status->isCompleted());

        $status->clearPauseStatus();

        // Should remain completed
        $this->assertTrue($status->isCompleted());
        $this->assertFalse($status->isPaused());
    }

    public function testClearPauseStatusWhenStoppedDoesNothing(): void
    {
        $status = new ExecutionStatus();

        $status->markStopped(['error' => 'test']);
        $this->assertTrue($status->isStopped());
        $this->assertSame(['error' => 'test'], $status->getMetadata());

        $status->clearPauseStatus();

        // Should remain stopped with metadata intact
        $this->assertTrue($status->isStopped());
        $this->assertSame(['error' => 'test'], $status->getMetadata());
    }

    public function testOnlyOneStatusCanBeTrueAtATime(): void
    {
        $status = new ExecutionStatus();

        $status->markCompleted();
        $this->assertTrue($status->isCompleted());
        $this->assertFalse($status->isPaused());
        $this->assertFalse($status->isStopped());

        $status->markPaused();
        $this->assertFalse($status->isCompleted());
        $this->assertTrue($status->isPaused());
        $this->assertFalse($status->isStopped());

        $status->markStopped();
        $this->assertFalse($status->isCompleted());
        $this->assertFalse($status->isPaused());
        $this->assertTrue($status->isStopped());
    }
}
