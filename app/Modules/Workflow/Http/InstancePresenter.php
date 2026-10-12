<?php

namespace App\Modules\Workflow\Http;

use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\User;
use App\Modules\OfficialDocuments\Http\OfficialDocumentController;
use App\Modules\OfficialDocuments\Models\OfficialDocument;
use App\Modules\OfficialDocuments\Services\OfficialDocumentAccess;
use App\Modules\Workflow\Engine\WorkflowEngine;
use App\Modules\Workflow\Forms\FormDataValidator;
use App\Modules\Workflow\Forms\OptionSources;
use App\Modules\Workflow\Models\WorkflowAction;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;

/** Shapes a request for the UI: form, values, timeline, progress, what the viewer can do. */
class InstancePresenter
{
    public function __construct(private FormDataValidator $forms, private WorkflowEngine $engine) {}

    public function summary(WorkflowInstance $i): array
    {
        return [
            'id' => $i->id,
            'reference' => $i->reference,
            'title' => $i->title,
            'status' => $i->status,
            'outcome' => $i->outcome,
            'workflow' => $i->relationLoaded('definition') ? $i->definition?->name : null,
            'initiator' => $i->relationLoaded('initiator') ? $i->initiator?->name : null,
            'department' => $i->relationLoaded('department') ? $i->department?->code : null,
            'submitted_at' => $i->submitted_at?->toIso8601String(),
            'completed_at' => $i->completed_at?->toIso8601String(),
            'updated_at' => $i->updated_at?->toIso8601String(),
            'current' => $i->relationLoaded('tasks') ? $i->tasks->where('status', 'pending')->map(fn ($t) => [
                'name' => $t->name,
                'assignee' => $t->relationLoaded('assignee') ? $t->assignee?->name : null,
                'due_at' => $t->due_at?->toIso8601String(),
                'overdue' => $t->isOverdue(),
            ])->values() : [],
        ];
    }

    public function detail(WorkflowInstance $instance, User $viewer): array
    {
        $instance->load([
            'definition:id,key,name,category', 'version.nodes', 'version.transitions', 'initiator.designation', 'initiator.department',
            'department:id,code,name', 'formVersion', 'tasks.assignee:id,name', 'tasks.principal:id,name', 'actions.actor:id,name', 'actions.onBehalfOf:id,name',
        ]);
        $schema = app(OptionSources::class)->resolve($instance->formVersion?->schema ?? ['sections' => []], $instance->initiator);
        $data = $instance->data ?? [];

        $myTasks = $instance->isOpen() ? $this->engine->pendingTasksOf($instance, $viewer) : collect();

        return [
            ...$this->summary($instance),
            'initiator' => [
                'id' => $instance->initiator->id,
                'name' => $instance->initiator->name,
                'designation' => $instance->initiator->designation?->name,
                'department' => $instance->initiator->department?->name,
            ],
            'workflow' => $instance->definition->name,
            'version' => $instance->version->version,
            // Official PDFs this request produces, with status in plain words (DOC-004).
            'official_documents' => OfficialDocument::query()->where('workflow_instance_id', $instance->id)->where('status', '!=', 'archived')
                ->with(['instance.definition:id,name', 'output.template:id,name', 'templateVersion:id,version'])->orderBy('id')->get()
                ->filter(fn ($d) => $d->status !== 'superseded')->values()
                ->map(fn ($d) => OfficialDocumentController::row($d, app(OfficialDocumentAccess::class)->canDownload($viewer, $d), $d->instance->initiator_id === $viewer->id)),
            'revision' => $instance->revision,
            'schema' => $schema,
            'data' => $data,
            'display' => $this->displayValues($schema, $data),
            'variables' => $instance->variables,
            'timeline' => $instance->actions->map(fn (WorkflowAction $a) => [
                'id' => $a->id,
                'action' => $a->action,
                'node' => $a->node_key ? $instance->version->nodes->firstWhere('key', $a->node_key)?->name : null,
                'actor' => $a->actor?->name,
                'on_behalf_of' => $a->onBehalfOf?->name,
                'remarks' => $a->remarks,
                'data' => $a->data,
                'at' => $a->created_at->toIso8601String(),
            ]),
            'tasks' => $instance->tasks->sortBy('id')->values()->map(fn (WorkflowTask $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'kind' => $t->kind,
                'status' => $t->status,
                'outcome' => $t->outcome,
                'assignee' => $t->assignee?->name,
                'principal' => $t->principal?->name,
                'due_at' => $t->due_at?->toIso8601String(),
                'overdue' => $t->isOverdue(),
                'completed_at' => $t->completed_at?->toIso8601String(),
            ]),
            'progress' => $this->progress($instance),
            'my_tasks' => $myTasks->map(function (WorkflowTask $t) use ($instance, $viewer) {
                $node = $instance->version->node($t->node_key);
                $actions = $t->kind === 'manual' ? ['complete', 'fail'] : $node->allowedActions();
                try {
                    $onBehalf = $this->engine->authorizeTask($t, $instance, $viewer, $actions[0]);
                } catch (\Throwable) {
                    return null;
                }

                return [
                    'id' => $t->id,
                    'node_key' => $t->node_key,
                    'name' => $t->name,
                    'kind' => $t->kind,
                    'actions' => $actions,
                    'labels' => $node->cfg('labels', []),
                    'instructions' => $node->cfg('instructions'),
                    'require_remarks_on' => $node->cfg('require_remarks_on', ['reject', 'return']),
                    'editable_fields' => array_values(array_column(array_filter($this->forms->fields($instance->formVersion?->schema ?? []), fn ($f) => in_array($node->key, $f['editable_at'] ?? [], true)), 'key')),
                    'on_behalf_of' => $onBehalf?->name,
                    'due_at' => $t->due_at?->toIso8601String(),
                ];
            })->filter()->values(),
            'documents' => Document::query()->where('attachable_type', $instance->getMorphClass())->where('attachable_id', $instance->id)
                ->orderBy('id')->get()->map->toSummary(),
            'can' => [
                'edit' => $viewer->can('edit', $instance),
                'cancel' => $instance->isOpen() && (($instance->initiator_id === $viewer->id && $instance->version->setting('initiator_can_cancel', true)) || $viewer->can('workflows.intervene')),
                'intervene' => $viewer->can('intervene', $instance),
                'resume' => $instance->status === 'on_hold',
            ],
        ];
    }

    /** Human-readable values for selector and file fields. */
    public function displayValues(array $schema, array $data): array
    {
        $out = [];
        foreach ($this->forms->fields($schema) as $f) {
            $v = $data[$f['key']] ?? null;
            if ($v === null || $v === [] || $v === '') {
                continue;
            }
            $out[$f['key']] = match ($f['type']) {
                'user', 'student', 'vendor' => User::query()->whereKey($v)->value('name'),
                'department' => Department::query()->whereKey($v)->value('name'),
                'select', 'radio' => collect($f['options'] ?? [])->firstWhere('value', $v)['label'] ?? $v,
                'multiselect' => collect($f['options'] ?? [])->whereIn('value', (array) $v)->pluck('label')->implode(', '),
                'file', 'files' => Document::query()->whereIn('uuid', (array) $v)->get()->map->toSummary()->values(),
                default => null,
            };
            if ($out[$f['key']] === null) {
                unset($out[$f['key']]);
            }
        }

        return $out;
    }

    /** Steps in rough order, marked done / current / upcoming, for the progress strip. */
    private function progress(WorkflowInstance $instance): array
    {
        $version = $instance->version;
        $visited = $instance->tasks->pluck('node_key')->merge($instance->actions->pluck('node_key'))->filter()->unique();
        $current = $instance->tasks->where('status', 'pending')->pluck('node_key')->unique();
        $order = $this->order($version);

        return collect($order)
            ->map(fn ($key) => $version->node($key))
            ->filter(fn ($n) => in_array($n->type, ['approval', 'review', 'verification', 'committee', 'signature', 'automation'], true))
            ->map(fn ($n) => [
                'key' => $n->key,
                'name' => $n->name,
                'state' => $current->contains($n->key) ? 'current' : ($visited->contains($n->key) ? 'done' : 'upcoming'),
            ])->values()->all();
    }

    /** Breadth-first order from start along approve/default/success paths. */
    private function order($version): array
    {
        $start = $version->startNode()->key;
        $seen = [$start => true];
        $queue = [$start];
        $order = [];
        while ($queue) {
            $key = array_shift($queue);
            $order[] = $key;
            foreach ($version->outgoing($key)->whereIn('outcome', ['approve', 'default', 'success']) as $t) {
                if (! isset($seen[$t->to_node])) {
                    $seen[$t->to_node] = true;
                    $queue[] = $t->to_node;
                }
            }
        }

        return $order;
    }
}
