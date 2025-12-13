<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Events;

abstract class Event
{
    public float $timestamp;

    public function __construct()
    {
        $this->timestamp = microtime(true);
    }
}
