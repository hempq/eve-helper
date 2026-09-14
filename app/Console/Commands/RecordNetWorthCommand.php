<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Services\Characters\NetWorthService;
use Illuminate\Console\Command;
use Throwable;

class RecordNetWorthCommand extends Command
{
    protected $signature = 'eve:record-net-worth';

    protected $description = 'Snapshot every character\'s net worth (wallet + priced assets + orders)';

    public function handle(NetWorthService $service): int
    {
        foreach (Character::all() as $character) {
            try {
                $worth = $service->record($character);
                $this->info(sprintf('%s: %s ISK', $character->name, number_format($worth->total)));
            } catch (Throwable $e) {
                $this->error("{$character->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
