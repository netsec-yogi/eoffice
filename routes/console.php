<?php

use Illuminate\Support\Facades\Schedule;

// Workflow clocks. Each command is idempotent; withoutOverlapping guards
// against a slow run being started twice.
Schedule::command('workflow:tick')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('workflow:sla-scan')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Directory refresh and audit chain verification (docs/SECURITY.md).
Schedule::command('directory:sync')->dailyAt('02:00')->withoutOverlapping()->onOneServer();
Schedule::command('audit:verify')->dailyAt('03:00')->onOneServer();

// Records management: flag files at the end of their retention period.
Schedule::command('efiles:retention-review')->dailyAt('04:00')->onOneServer();
