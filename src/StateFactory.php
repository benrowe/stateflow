<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

interface StateFactory
{
    public function fromArray(array $data): State;
}
