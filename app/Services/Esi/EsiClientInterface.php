<?php

namespace App\Services\Esi;

use App\Models\Character;

interface EsiClientInterface
{
    /**
     * Perform a GET request against ESI. Pass a character for endpoints that
     * require authentication. Responses are served from cache until their
     * ESI Expires timestamp, then revalidated with If-None-Match.
     */
    public function get(string $path, array $query = [], ?Character $character = null): EsiResponse;

    /**
     * Fetch every page of a paginated list endpoint (X-Pages) and return the
     * merged rows.
     */
    public function getAllPages(string $path, array $query = [], ?Character $character = null): array;

    /**
     * Authenticated write (e.g. setting an autopilot waypoint). Never cached.
     */
    public function post(string $path, array $query, Character $character): void;
}
