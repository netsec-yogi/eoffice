<?php

namespace App\Modules\OfficialDocuments\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Forms\OptionSources;
use App\Modules\Workflow\Http\InstancePresenter;
use App\Modules\Workflow\Models\WorkflowAction;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowVersion;
use Carbon\CarbonImmutable;

/**
 * Everything a template may print, read from the request as stored
 * (DOC-002 §4.3): no value is typed in twice. Paths:
 *   applicant.*  form.<field>  form_label.<field>  workflow.*
 *   approval.<step>.{name,designation,department,date,remarks,decision,statement}
 *   approvals (list)  institute.*  document.*
 */
class DocumentData
{
    public const APPLICANT = ['name', 'username', 'email', 'employee_id', 'category', 'department', 'department_code', 'designation'];

    public const WORKFLOW = ['reference', 'title', 'name', 'submitted_at', 'completed_at', 'status'];

    public const APPROVAL = ['name', 'designation', 'department', 'date', 'remarks', 'decision', 'on_behalf_of', 'statement'];

    public const DOCUMENT = ['generated_at', 'version', 'template_version'];

    public function __construct(private InstancePresenter $presenter) {}

    public function for(WorkflowInstance $instance, array $document = []): array
    {
        $instance->loadMissing(['definition:id,key,name', 'formVersion', 'initiator.department', 'initiator.designation']);
        // Live option sources resolved as the applicant saw them, so a select prints its label.
        $schema = app(OptionSources::class)->resolve($instance->formVersion?->schema ?? ['sections' => []], $instance->initiator);
        $data = $instance->data ?? [];
        $labels = array_map(fn ($v) => is_string($v) ? $v : null, $this->presenter->displayValues($schema, $data));

        $approvals = WorkflowAction::query()->where('workflow_instance_id', $instance->id)->where('action', 'approved')
            ->with(['actor.designation', 'actor.department', 'onBehalfOf:id,name'])->orderBy('id')->get();
        $steps = WorkflowVersion::query()->find($instance->workflow_version_id)?->nodes()->pluck('name', 'key') ?? collect();
        $byStep = [];
        $list = [];
        foreach ($approvals as $a) {
            $row = $this->approval($a, $steps[$a->node_key] ?? $a->node_key);
            $byStep[$a->node_key] = $row; // the latest approval of each step
            $list[] = $row;
        }

        return [
            'applicant' => $this->person($instance->initiator),
            'form' => $data,
            'form_label' => array_filter($labels, fn ($v) => $v !== null) + array_filter($data, 'is_scalar'),
            'workflow' => [
                'reference' => $instance->reference, 'title' => $instance->title, 'name' => $instance->definition->name,
                'submitted_at' => $instance->submitted_at?->toIso8601String(), 'completed_at' => $instance->completed_at?->toIso8601String(), 'status' => $instance->status,
            ],
            'approval' => $byStep,
            'approvals' => $list,
            'institute' => config('official_documents.institute'),
            'document' => $document + ['generated_at' => now()->toIso8601String()],
        ];
    }

    /** Synthetic values for an administrator's preview: never real people's data. */
    public function sample(array $mapping, array $steps = []): array
    {
        $form = [];
        // Placeholders in fixed text count as sources too.
        $entries = [];
        foreach ($mapping as $f) {
            $entries[] = $f;
            preg_match_all('/\{\{\s*([\w.\-]+)\s*\}\}/', (string) ($f['text'] ?? ''), $ph);
            foreach ($ph[1] as $path) {
                $entries[] = ['source' => $path, 'type' => 'text'];
            }
        }
        foreach ($entries as $f) {
            $path = (string) ($f['source'] ?? '');
            if (preg_match('/^form(_label)?\.([\w-]+)/', $path, $m)) {
                $form[$m[2]] = match ($f['type'] ?? 'text') {
                    'checkbox' => $f['checkbox']['match'] ?? true,
                    'table' => [$this->sampleRow($f['table']['columns'] ?? [], 1), $this->sampleRow($f['table']['columns'] ?? [], 2)],
                    'multiline' => 'Sample text for the preview. It wraps over more than one line to show how longer entries are laid out in this box.',
                    default => match ($f['format']['kind'] ?? 'auto') {
                        'date', 'datetime' => now()->toDateString(), 'amount', 'number', 'words' => '12345.5', default => (float) ($f['w'] ?? 200) >= 120 ? 'Sample '.$m[2] : 'Sample'
                    },
                };
            }
        }
        $officer = ['name' => 'Sample Officer', 'designation' => 'Designation', 'department' => 'Department', 'date' => now()->toIso8601String(), 'remarks' => 'Sample remarks', 'decision' => 'Approved', 'on_behalf_of' => null];
        $officer['statement'] = $this->statement($officer);
        $approval = [];
        foreach ($steps ?: array_unique(array_map(fn ($f) => explode('.', (string) ($f['source'] ?? ''))[1] ?? '', array_filter($mapping, fn ($f) => str_starts_with((string) ($f['source'] ?? ''), 'approval.')))) as $step) {
            $approval[$step] = $officer;
        }

        return [
            'applicant' => ['name' => 'Sample Applicant', 'username' => 'sample', 'email' => 'sample@example.test', 'employee_id' => 'E0000', 'category' => 'staff', 'department' => 'Sample Department', 'department_code' => 'SMP', 'designation' => 'Sample Designation'],
            'form' => $form, 'form_label' => array_filter($form, 'is_scalar'),
            'workflow' => ['reference' => 'SAMPLE/2026/0001', 'title' => 'Sample request', 'name' => 'Sample workflow', 'submitted_at' => now()->subDays(3)->toIso8601String(), 'completed_at' => now()->toIso8601String(), 'status' => 'completed'],
            'approval' => $approval, 'approvals' => array_values($approval),
            'institute' => config('official_documents.institute'),
            'document' => ['generated_at' => now()->toIso8601String(), 'version' => 1, 'template_version' => 0],
        ];
    }

    /** A synthetic table row whose cells match their column formats. */
    private function sampleRow(array $columns, int $n): array
    {
        $row = [];
        foreach ($columns as $c) {
            $row[$c['path'] ?? ''] = match ($c['format']['kind'] ?? 'auto') {
                'date', 'datetime' => now()->subDays($n)->toDateString(),
                'amount', 'number', 'words' => (string) (1000 * $n + 50),
                default => "Sample {$n}",
            };
        }

        return $row;
    }

    private function person(User $u): array
    {
        return [
            'name' => $u->name, 'username' => $u->username, 'email' => $u->email, 'employee_id' => $u->employee_id, 'category' => $u->category,
            'department' => $u->department?->name, 'department_code' => $u->department?->code, 'designation' => $u->designation?->name,
        ];
    }

    private function approval(WorkflowAction $a, string $step): array
    {
        $row = [
            'step' => $step, 'name' => $a->actor?->name, 'designation' => $a->actor?->designation?->name, 'department' => $a->actor?->department?->name,
            'date' => $a->created_at->toIso8601String(), 'remarks' => $a->remarks, 'decision' => 'Approved', 'on_behalf_of' => $a->onBehalfOf?->name,
        ];

        return $row + ['statement' => $this->statement($row)];
    }

    private function statement(array $row): string
    {
        return strtr(config('official_documents.approval_statement'), [
            ':name' => (string) $row['name'], ':designation' => (string) ($row['designation'] ?? '—'),
            ':date' => CarbonImmutable::parse($row['date'])->format(config('official_documents.date_format')),
        ]);
    }
}
