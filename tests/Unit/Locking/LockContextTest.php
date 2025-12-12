<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Locking;

use BenRowe\StateFlow\Locking\LockConfiguration;
use BenRowe\StateFlow\Locking\LockContext;
use BenRowe\StateFlow\Locking\LockKeyProvider;
use BenRowe\StateFlow\Locking\LockProvider;
use BenRowe\StateFlow\Locking\LockStrategy;
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
