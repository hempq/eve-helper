<?php

namespace App\Console\Commands;

use App\Services\Alerts\AlertService;
use Illuminate\Console\Command;

class CheckAlertsCommand extends Command
{
    protected $signature = 'eve:check-alerts';

    protected $description = 'Refresh in-app alerts (skill queue, undercut orders, expiring escalations)';

    public function handle(AlertService $alerts): int
    {
        $count = $alerts->refreshAll();
        $this->info("Live alerts across all characters: {$count}.");

        return self::SUCCESS;
    }
}
