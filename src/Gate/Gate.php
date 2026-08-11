<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Gate;

interface Gate
{
    public function evaluate(GateContext $context): GateResult;

    public function message(): ?string;
}
