<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit\Locking;

use CoverGenius\StateFlow\Locking\LockConfiguration;
use CoverGenius\StateFlow\Locking\LockContext;
use CoverGenius\StateFlow\Locking\LockKeyProvider;
use CoverGenius\StateFlow\Locking\LockProvider;
use CoverGenius\StateFlow\Locking\LockStrategy;
use PHPUnit\Framework\TestCase;

final class LockContextTest extends TestCase
{
    public function testItStoresLockDependencies(): void
    {
        $provider = $this->createMock(LockProvider::class);
        $keyProvider = $this->createMock(LockKeyProvider::class);
        $configuration = new LockConfiguration(LockStrategy::FAIL_FAST, 30);

        $context = new LockContext($provider, $keyProvider, $configuration);

        $this->assertSame($provider, $context->provider);
        $this->assertSame($keyProvider, $context->keyProvider);
        $this->assertSame($configuration, $context->configuration);
    }

    public function testItIsReadonly(): void
    {
        $provider = $this->createMock(LockProvider::class);
        $keyProvider = $this->createMock(LockKeyProvider::class);
        $configuration = new LockConfiguration(LockStrategy::FAIL_FAST, 30);

        $context = new LockContext($provider, $keyProvider, $configuration);

        $reflection = new \ReflectionClass($context);
        $this->assertTrue($reflection->isReadOnly());
    }
}
