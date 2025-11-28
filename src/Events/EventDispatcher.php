<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Events;

interface EventDispatcher
{
    public function dispatch(Event $event): void;
}
