<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow;

interface StateFactory
{
    /**
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): State;
}
