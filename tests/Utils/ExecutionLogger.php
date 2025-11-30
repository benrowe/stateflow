<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Utils;

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
