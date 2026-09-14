<?php

namespace App\Services\Esi\Exceptions;

use RuntimeException;

class EsiErrorLimited extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(sprintf(
            'ESI error budget exhausted; back off for %d seconds.',
            $retryAfterSeconds,
        ));
    }
}
