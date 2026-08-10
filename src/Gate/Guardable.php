<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Gate;

interface Guardable
{
    public function gate(): Gate;
}
