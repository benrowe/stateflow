<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Gate;

interface Gate
{
    public function evaluate(GateContext $context): GateResult;

    public function message(): ?string;
}
