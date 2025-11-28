<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

/**
 * StateFlow - A flexible state machine implementation
 */
class StateFlow
{
    public function __construct()
    {
    }

    /**
     * @param array<string, mixed> $array
     */
    public function transition(State $param, array $array): StateWorker
    {
        return new StateWorker();
    }
}
