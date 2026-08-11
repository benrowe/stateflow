<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Events;

interface EventDispatcher
{
    public function dispatch(Event $event): void;
}
