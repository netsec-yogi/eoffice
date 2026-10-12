<?php

namespace App\Modules\ComputerCentre\Actions;

use App\Modules\ComputerCentre\Models\SoftwareTitle;
use App\Modules\ComputerCentre\Services\LicenceService;
use App\Modules\Workflow\Automation\AutomationAction;
use App\Modules\Workflow\Automation\AutomationResult;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Validation\ValidationException;

class AllocateLicence implements AutomationAction
{
    use ResolvesAccountHolder;

    public function __construct(private LicenceService $licences) {}

    public function label(): string
    {
        return 'Computer Centre: allocate a software licence seat';
    }

    public function settings(): array
    {
        return [];
    }

    public function run(IntegrationJob $job, WorkflowInstance $instance, array $config): AutomationResult
    {
        $title = SoftwareTitle::query()->where('code', $instance->data['software'] ?? '')->first();
        if (! $title) {
            return AutomationResult::failure('The requested software is not in the licence register.');
        }
        try {
            $this->licences->allocate($title, $this->holder($instance), null, $instance->id);
        } catch (ValidationException $e) {
            return AutomationResult::failure(collect($e->errors())->flatten()->first());
        }

        return AutomationResult::success(['licence' => $title->code]);
    }
}
