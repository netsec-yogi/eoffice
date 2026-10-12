<?php

namespace App\Modules\Workflow\Jobs;

use App\Modules\Workflow\Automation\ActionRegistry;
use App\Modules\Workflow\Automation\AutomationResult;
use App\Modules\Workflow\Engine\WorkflowEngine;
use App\Modules\Workflow\Models\IntegrationJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Runs one automation step. Idempotent: a job that already finished is
 * ignored, so queue retries and duplicate dispatches are harmless.
 */
class RunAutomation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $jobId) {}

    public function handle(ActionRegistry $registry, WorkflowEngine $engine): void
    {
        $job = IntegrationJob::query()->find($this->jobId);
        if (! $job || in_array($job->status, ['succeeded', 'failed', 'blocked'], true)) {
            return;
        }
        $job->forceFill(['status' => 'running', 'attempts' => $job->attempts + 1])->save();

        $action = $registry->make($job->action);
        if (! $action) {
            $engine->automationFinished($job, AutomationResult::blocked("Automation action '{$job->action}' is not installed."));

            return;
        }

        try {
            $result = $action->run($job, $job->instance()->firstOrFail(), $job->input ?? []);
        } catch (Throwable $e) {
            $job->forceFill(['status' => 'pending', 'last_error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            if ($this->attempts() < $this->tries) {
                throw $e; // transient: retry with backoff
            }
            report($e);
            $result = AutomationResult::failure('The integration failed after '.$this->tries.' attempts: '.mb_substr($e->getMessage(), 0, 300));
        }

        $engine->automationFinished($job, $result);
    }
}
