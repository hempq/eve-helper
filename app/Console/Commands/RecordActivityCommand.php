<?php

namespace App\Console\Commands;

use App\Services\Farm\ActivityRecorder;
use Illuminate\Console\Command;

class RecordActivityCommand extends Command
{
    protected $signature = 'eve:record-activity';

    protected $description = 'Store the hourly ESI kill/jump snapshot into system_activity (run hourly)';

    public function handle(ActivityRecorder $recorder): int
    {
        $rows = $recorder->record();

        $this->info($rows > 0
            ? "Recorded activity for {$rows} systems."
            : 'Skipped — the latest snapshot is still fresh.');

        return self::SUCCESS;
    }
}
