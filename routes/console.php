<?php

use Illuminate\Support\Facades\Schedule;

// Hourly activity snapshot for farm scoring (requires `schedule:work` or
// cron; the eve:record-activity guard makes double runs harmless).
Schedule::command('eve:record-activity')->hourly();

// Refresh the trade finder's hub order books twice a day.
Schedule::command('eve:scan-hubs')->twiceDaily(9, 19);
