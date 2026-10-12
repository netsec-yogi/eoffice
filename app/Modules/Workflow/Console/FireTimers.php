<?php

namespace App\Modules\Workflow\Console;

use App\Modules\Workflow\Engine\WorkflowEngine;
use App\Modules\Workflow\Models\WorkflowToken;
use Illuminate\Console\Command;
use Throwable;

class FireTimers extends Command
{
    protected $signature = 'workflow:tick';

    protected $description = 'Continue workflows whose timer steps have elapsed';

    public function handle(WorkflowEngine $engine): int
    {
        $fired = 0;
        WorkflowToken::query()->where('status', 'waiting')->whereNotNull('wake_at')->where('wake_at', '<=', now())
            ->orderBy('wake_at')->limit(500)->get()
            ->each(function (WorkflowToken $token) use ($engine, &$fired) {
                try {
                    $engine->fireTimer($token);
                    $fired++;
                } catch (Throwable $e) {
                    report($e);
                }
            });
        $this->info("Timers fired: {$fired}");

        return self::SUCCESS;
    }
}
