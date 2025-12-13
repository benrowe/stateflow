<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Events;

/**
 * Base class for all StateFlow events
 *
 * @SuppressWarnings(PHPMD.NumberOfChildren) Event hierarchy naturally grows with domain events (16 children, threshold 15)
 */
abstract class Event
{
    public float $timestamp;

    public function __construct()
    {
        $this->timestamp = microtime(true);
    }
}
