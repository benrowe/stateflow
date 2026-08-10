<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Action;

/**
 * Wraps response data supplied to StateWorker::resumeWithResponse(), distinguishing
 * "no response" from "response data is null" without a boolean flag argument.
 */
final readonly class YieldResponse
{
    public function __construct(public mixed $data) {}
}
