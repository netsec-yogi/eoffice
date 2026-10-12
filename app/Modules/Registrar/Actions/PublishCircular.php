<?php

namespace App\Modules\Registrar\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Registrar\Models\Circular;
use App\Modules\Registrar\Services\CircularService;
use App\Modules\Workflow\Automation\AutomationAction;
use App\Modules\Workflow\Automation\AutomationResult;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowInstance;

class PublishCircular implements AutomationAction
{
    public function __construct(private CircularService $circulars) {}

    public function label(): string
    {
        return 'Registrar: publish the approved circular';
    }

    public function settings(): array
    {
        return [];
    }

    public function run(IntegrationJob $job, WorkflowInstance $instance, array $config): AutomationResult
    {
        if ($instance->subject_type !== 'circular' || ! ($c = Circular::query()->find($instance->subject_id))) {
            return AutomationResult::failure('This request is not about a circular.');
        }
        $approver = $instance->actions()->where('action', 'approved')->reorder()->orderByDesc('id')->value('actor_id');
        $c = $this->circulars->publish($c, $approver ? User::query()->find($approver) : null);

        return AutomationResult::success(['circular_number' => $c->number], "Published {$c->number}.");
    }
}
