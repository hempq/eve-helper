<?php

namespace App\Services\Farm;

use Illuminate\Support\Collection;

/**
 * Scored systems of a region, plus whether the scores came from averaged
 * activity history or the live snapshot fallback. (A declared property —
 * dynamic properties on Collection are deprecated in PHP 8.2+.)
 *
 * @extends Collection<int, object>
 */
class ScoredSystems extends Collection
{
    public bool $usingHistory = false;
}
