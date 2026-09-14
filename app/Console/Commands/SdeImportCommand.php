<?php

namespace App\Console\Commands;

use App\Services\Sde\SdeDownloader;
use App\Services\Sde\SdeImporter;
use Illuminate\Console\Command;
use RuntimeException;

class SdeImportCommand extends Command
{
    protected $signature = 'eve:sde-import
        {--dir= : Import from a directory of already-downloaded Fuzzwork CSVs instead of downloading}';

    protected $description = 'Download (Fuzzwork) and import the EVE Static Data Export subset the app uses';

    public function handle(SdeDownloader $downloader, SdeImporter $importer): int
    {
        $dir = $this->option('dir');

        if ($dir === null) {
            $dir = storage_path('app/sde');
            $this->info("Downloading SDE files to {$dir} ...");
            $downloader->downloadAll($dir, fn (string $file) => $this->line("  fetching {$file}"));
        }

        $steps = [
            'item types' => fn () => $importer->importTypes($this->resolve($dir, 'invTypes.csv')),
            'skills' => fn () => $importer->importSkills(
                $this->resolve($dir, 'invGroups.csv'),
                $this->resolve($dir, 'dgmTypeAttributes.csv'),
            ),
            'regions' => fn () => $importer->importRegions($this->resolve($dir, 'mapRegions.csv')),
            'constellations' => fn () => $importer->importConstellations($this->resolve($dir, 'mapConstellations.csv')),
            'solar systems' => fn () => $importer->importSolarSystems($this->resolve($dir, 'mapSolarSystems.csv')),
            'system jumps' => fn () => $importer->importSystemJumps($this->resolve($dir, 'mapSolarSystemJumps.csv')),
        ];

        foreach ($steps as $label => $step) {
            $count = $step();
            $this->info(sprintf('Imported %s %s.', number_format($count), $label));
        }

        return self::SUCCESS;
    }

    private function resolve(string $dir, string $file): string
    {
        foreach ([$dir.'/'.$file, $dir.'/'.$file.'.bz2'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException("SDE file not found: {$dir}/{$file}[.bz2]");
    }
}
