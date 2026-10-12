<?php

namespace App\Modules\Workflow\Graph;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Engine\WorkflowException;
use App\Modules\Workflow\Models\FormVersion;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowNode;
use App\Modules\Workflow\Models\WorkflowTransition;
use App\Modules\Workflow\Models\WorkflowVersion;
use Illuminate\Support\Facades\DB;

/**
 * Administrator-side lifecycle of workflow definitions (WF-001..004):
 * create, edit the draft graph, validate, publish an immutable version,
 * open a new draft from the published one. No code change is ever needed to
 * change a route, a step or a deadline.
 */
class WorkflowDesigner
{
    public function __construct(private GraphValidator $validator, private AuditLogger $audit) {}

    public function create(array $data, User $by): WorkflowDefinition
    {
        return DB::transaction(function () use ($data, $by) {
            $definition = new WorkflowDefinition($data);
            $definition->forceFill(['created_by' => $by->id])->save();

            $version = $this->newVersion($definition, 1, $by, ['initiators' => [], 'initiator_can_cancel' => true]);
            $this->replaceGraph($version, [
                ['key' => 'start', 'type' => 'start', 'name' => 'Submitted', 'config' => [], 'x' => 0, 'y' => 0],
                ['key' => 'end_approved', 'type' => 'end', 'name' => 'Approved', 'config' => ['outcome' => 'approved'], 'x' => 0, 'y' => 240],
            ], [
                ['key' => 't1', 'from_node' => 'start', 'to_node' => 'end_approved', 'outcome' => 'default'],
            ]);
            $this->audit->record('workflow.definition.created', $definition, $definition->only('key', 'name', 'category'), actor: $by);

            return $definition;
        });
    }

    /**
     * Replace the draft's graph and settings with what the designer sent.
     *
     * @param  array{nodes: list<array>, edges: list<array>, settings?: array, form_version_id?: ?int}  $payload
     */
    public function saveDraft(WorkflowVersion $version, array $payload, User $by): WorkflowVersion
    {
        if (! $version->isDraft()) {
            throw new WorkflowException('Published versions cannot be edited. Open a new draft.', 409);
        }
        if (! empty($payload['form_version_id']) && FormVersion::query()->whereKey($payload['form_version_id'])->value('status') !== 'published') {
            throw new WorkflowException('Attach a published form version.', 422, ['form_version_id' => 'Publish the form first.']);
        }

        return DB::transaction(function () use ($version, $payload, $by) {
            $version->forceFill([
                'settings' => $payload['settings'] ?? $version->settings,
                'form_version_id' => $payload['form_version_id'] ?? null,
            ])->save();
            $this->replaceGraph($version, $payload['nodes'], $payload['edges']);
            $this->audit->record('workflow.version.saved', $version, ['nodes' => count($payload['nodes']), 'edges' => count($payload['edges'])], actor: $by);

            return $version->refresh();
        });
    }

    /** @return array{errors: list<array>, warnings: list<array>} */
    public function check(WorkflowVersion $version): array
    {
        $version->loadMissing('nodes', 'transitions', 'formVersion');

        return $this->validator->check(
            $version->nodes->map(fn ($n) => $n->only('key', 'type', 'name', 'config'))->all(),
            $version->transitions->map(fn ($t) => $t->only('key', 'from_node', 'to_node', 'outcome', 'condition'))->all(),
            $version->formVersion?->schema,
            $version->settings ?? [],
        );
    }

    public function publish(WorkflowVersion $version, User $by, ?string $note = null): WorkflowVersion
    {
        return DB::transaction(function () use ($version, $by, $note) {
            $version = WorkflowVersion::query()->lockForUpdate()->findOrFail($version->id);
            if (! $version->isDraft()) {
                throw new WorkflowException('This version is already published.', 409);
            }
            $result = $this->check($version);
            if ($result['errors'] !== []) {
                $first = $result['errors'][0]['message'];
                $more = count($result['errors']) > 1 ? ' (and '.(count($result['errors']) - 1).' more)' : '';
                throw new WorkflowException("Fix before publishing: {$first}{$more}", 422, ['graph' => $first]);
            }

            // Earlier published versions stop taking new requests; running
            // requests stay on them (WF-002).
            WorkflowVersion::query()->where('workflow_definition_id', $version->workflow_definition_id)
                ->where('status', 'published')->update(['status' => 'retired', 'updated_at' => now()]);

            $version->forceFill([
                'status' => 'published',
                'published_at' => now(),
                'published_by' => $by->id,
                'change_note' => $note,
            ]);
            $version->checksum = hash('sha256', json_encode([
                $version->nodes->map(fn ($n) => $n->only('key', 'type', 'name', 'config'))->sortBy('key')->values(),
                $version->transitions->map(fn ($t) => $t->only('from_node', 'to_node', 'outcome', 'condition', 'priority'))->values(),
                $version->settings, $version->form_version_id,
            ]));
            // Status and checksum are written in one statement: the model's
            // immutability guard applies to versions that were already published.
            WorkflowVersion::query()->whereKey($version->id)->update([
                'status' => 'published', 'published_at' => $version->published_at, 'published_by' => $by->id,
                'change_note' => $note, 'checksum' => $version->checksum, 'updated_at' => now(),
            ]);
            $this->audit->record('workflow.version.published', $version, [
                'definition' => $version->definition()->value('key'), 'version' => $version->version, 'checksum' => $version->checksum, 'note' => $note,
                'warnings' => array_column($result['warnings'], 'message'),
            ], actor: $by);

            return $version->refresh();
        });
    }

    /** The current draft, or a new draft copied from the latest version. */
    public function draft(WorkflowDefinition $definition, User $by): WorkflowVersion
    {
        return DB::transaction(function () use ($definition, $by) {
            WorkflowDefinition::query()->lockForUpdate()->findOrFail($definition->id);
            if ($draft = $definition->draftVersion()->first()) {
                return $draft;
            }
            $source = WorkflowVersion::query()->where('workflow_definition_id', $definition->id)->with('nodes', 'transitions')->orderByDesc('version')->firstOrFail();
            $draft = $this->newVersion($definition, $source->version + 1, $by, $source->settings, $source->form_version_id);
            $this->replaceGraph(
                $draft,
                $source->nodes->map(fn ($n) => [...$n->only('key', 'type', 'name', 'config'), 'x' => $n->position_x, 'y' => $n->position_y])->all(),
                $source->transitions->map(fn ($t) => $t->only('key', 'from_node', 'to_node', 'outcome', 'condition', 'priority', 'label'))->all(),
            );
            $this->audit->record('workflow.version.drafted', $draft, ['from_version' => $source->version], actor: $by);

            return $draft;
        });
    }

    private function newVersion(WorkflowDefinition $definition, int $number, User $by, array $settings, ?int $formVersionId = null): WorkflowVersion
    {
        $version = new WorkflowVersion;
        $version->forceFill([
            'workflow_definition_id' => $definition->id,
            'version' => $number,
            'status' => 'draft',
            'settings' => $settings,
            'form_version_id' => $formVersionId,
            'created_by' => $by->id,
        ])->save();

        return $version;
    }

    private function replaceGraph(WorkflowVersion $version, array $nodes, array $edges): void
    {
        WorkflowTransition::query()->where('workflow_version_id', $version->id)->get()->each->delete();
        WorkflowNode::query()->where('workflow_version_id', $version->id)->get()->each->delete();

        foreach ($nodes as $n) {
            (new WorkflowNode)->forceFill([
                'workflow_version_id' => $version->id,
                'key' => $n['key'],
                'type' => $n['type'],
                'name' => $n['name'],
                'config' => $n['config'] ?? [],
                'position_x' => (int) round($n['x'] ?? 0),
                'position_y' => (int) round($n['y'] ?? 0),
            ])->save();
        }
        foreach (array_values($edges) as $i => $e) {
            (new WorkflowTransition)->forceFill([
                'workflow_version_id' => $version->id,
                'key' => $e['key'] ?? 't'.($i + 1),
                'from_node' => $e['from_node'],
                'to_node' => $e['to_node'],
                'outcome' => $e['outcome'] ?? 'default',
                'condition' => ! empty($e['condition']) ? $e['condition'] : null,
                'priority' => (int) ($e['priority'] ?? 100),
                'label' => $e['label'] ?? null,
            ])->save();
        }
        $version->unsetRelation('nodes')->unsetRelation('transitions');
    }
}
