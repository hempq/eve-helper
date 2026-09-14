<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Services\Characters\CharacterSyncService;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use Illuminate\Console\Command;

/**
 * Background sync for every stored character, so the app stays fresh without
 * anyone loading a page (undercut alerts, farm activity, wallet, assets).
 */
class SyncCharactersCommand extends Command
{
    protected $signature = 'eve:sync-characters {--force : Ignore the staleness guard}';

    protected $description = 'Sync all stored characters from ESI (skills, queue, wallet, assets, orders)';

    public function handle(CharacterSyncService $sync): int
    {
        $characters = Character::all();

        if ($characters->isEmpty()) {
            $this->info('No characters to sync.');

            return self::SUCCESS;
        }

        foreach ($characters as $character) {
            try {
                $sync->sync($character, force: (bool) $this->option('force'));
                $this->line("  synced {$character->name}");
            } catch (EsiErrorLimited $e) {
                $this->warn("  {$character->name}: error-limited, backing off ({$e->retryAfterSeconds}s)");

                break; // stop the whole run; the budget is shared
            } catch (EsiRequestFailed $e) {
                $this->warn("  {$character->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
