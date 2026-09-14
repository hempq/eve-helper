<?php

namespace App\Services\Esi\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

class EsiRequestFailed extends RuntimeException
{
    public static function fromResponse(string $path, Response $response): self
    {
        return new self(sprintf(
            'ESI request GET %s failed with status %d: %s',
            $path,
            $response->status(),
            $response->json('error') ?? $response->body(),
        ), $response->status());
    }
}
