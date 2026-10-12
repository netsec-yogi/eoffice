<?php

namespace App\Modules\Workflow\Console;

use App\Modules\Workflow\Engine\SlaMonitor;
use Illuminate\Console\Command;

class ScanSla extends Command
{
    protected $signature = 'workflow:sla-scan';

    protected $description = 'Send due reminders and escalations for workflow tasks (idempotent)';

    public function handle(SlaMonitor $monitor): int
    {
        $result = $monitor->scan();
        $this->info(json_encode($result));

        return self::SUCCESS;
    }
}
