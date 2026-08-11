<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow;

interface State
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): static;
}
