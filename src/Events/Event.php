<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Events;

/**
 * @SuppressWarnings(PHPMD.NumberOfChildren)
 */
abstract class Event
{
    public float $timestamp;

    public function __construct()
    {
        $this->timestamp = microtime(true);
    }
}
