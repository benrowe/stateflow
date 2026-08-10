<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Events;

/**
 * A null event dispatcher that does nothing.
 */
class NullEventDispatcher implements EventDispatcher
{
    public function dispatch(Event $event): void
    {
        // Do nothing.
    }
}
