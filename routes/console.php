<?php

use Illuminate\Support\Facades\Schedule;

// Background character sync so undercut/wallet/asset/farm data stays fresh
// without a page load. The service's own 5-minute staleness guard and the
// ESI cache keep this cheap.
Schedule::command('eve:sync-characters')->everyFifteenMinutes()->withoutOverlapping();

// In-app alerts (skill queue, undercut orders, expiring escalations).
Schedule::command('eve:check-alerts')->everyThirtyMinutes()->withoutOverlapping();

// Hourly activity snapshot for farm scoring (requires `schedule:work` or
// cron; the eve:record-activity guard makes double runs harmless).
Schedule::command('eve:record-activity')->hourly()->withoutOverlapping();

// Refresh the trade finder's hub order books twice a day.
Schedule::command('eve:scan-hubs')->twiceDaily(9, 19)->withoutOverlapping();
