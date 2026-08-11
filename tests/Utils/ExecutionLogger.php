<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Utils;

/**
 * Helper class to track execution order in tests
 */
class ExecutionLogger
{
    /**
     * @var array<int, string>
     */
    public array $log = [];
}
