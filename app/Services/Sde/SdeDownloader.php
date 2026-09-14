<?php

namespace App\Services\Sde;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Downloads the Fuzzwork SDE CSV dumps the importer consumes.
 */
class SdeDownloader
{
    public const FILES = [
        'invTypes.csv',
        'invGroups.csv',
        'dgmTypeAttributes.csv',
        'staStations.csv',
        'mapRegions.csv',
        'mapConstellations.csv',
        'mapSolarSystems.csv',
        'mapSolarSystemJumps.csv',
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $userAgent,
    ) {}

    /**
     * Download all needed files into $targetDir; returns filename => path.
     *
     * @return array<string, string>
     */
    public function downloadAll(string $targetDir, ?callable $onFile = null): array
    {
        if (! is_dir($targetDir) && ! mkdir($targetDir, 0755, true)) {
            throw new RuntimeException("Cannot create SDE directory: {$targetDir}");
        }

        $paths = [];

        foreach (self::FILES as $file) {
            $path = $targetDir.'/'.$file;

            if ($onFile !== null) {
                $onFile($file);
            }

            Http::withHeaders(['User-Agent' => $this->userAgent])
                ->timeout(600)
                ->sink($path)
                ->get($this->baseUrl.$file)
                ->throw();

            $paths[$file] = $path;
        }

        return $paths;
    }
}
