<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Events;

/**
 * Base class for all StateFlow events
 *
 * Event hierarchy naturally grows with domain events (16 children, threshold 15)
 *
 * @SuppressWarnings("PHPMD.NumberOfChildren")
 */
abstract class Event
{
    public float $timestamp;

    public function __construct()
    {
        $this->timestamp = microtime(true);
    }
}
