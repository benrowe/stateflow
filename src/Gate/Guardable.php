<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Gate;

interface Guardable
{
    public function gate(): Gate;
}
