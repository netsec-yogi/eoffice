<?php

namespace Database\Seeders\Concerns;

use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Forms\FormService;
use App\Modules\Workflow\Graph\WorkflowDesigner;
use App\Modules\Workflow\Models\FormTemplate;
use App\Modules\Workflow\Models\WorkflowDefinition;

/**
 * Builds a form + workflow through the same services the designer uses and
 * publishes it. Existing workflows are never overwritten.
 */
trait DefinesWorkflows
{
    protected User $actor;

    /**
     * @param  list<array>  $fields  form fields (one section)
     * @param  list<array{0:string,1:string,2:string,3?:array}>  $nodes  without the start node
     * @param  list<array>  $edges  [from, to, outcome?, condition?, priority?]
     */
    protected function define(string $key, string $name, string $description, string $category, array $fields, array $nodes, array $edges, string $title, array $settings = []): void
    {
        $existing = WorkflowDefinition::query()->where('key', $key)->first();
        if ($existing?->currentVersion()->exists()) {
            $this->command?->line("  {$key}: exists, left unchanged");

            return;
        }
        $forms = app(FormService::class);
        $template = FormTemplate::query()->where('key', $key)->first() ?? $forms->create(['key' => $key, 'name' => $name], $this->actor);
        $formVersion = $template->versions()->first();
        if ($formVersion->status === 'draft') {
            $forms->saveDraft($formVersion, ['sections' => [['key' => 'request', 'title' => $name, 'fields' => $fields]]], $this->actor);
            $formVersion = $forms->publish($formVersion, $this->actor);
        }

        $designer = app(WorkflowDesigner::class);
        // A definition left unpublished by an earlier failed run is completed, not duplicated.
        $definition = $existing ?? $designer->create([
            'key' => $key, 'name' => $name, 'description' => $description, 'category' => $category,
            'numbering_pattern' => strtoupper(str_replace('-', '', $key)).'/{YYYY}/{SEQ:5}',
        ], $this->actor);
        $draft = $designer->draft($definition, $this->actor);

        $all = [['start', 'start', 'Submitted', []], ...$nodes];
        $layout = $this->layout($all, $edges);
        $designer->saveDraft($draft, [
            'nodes' => array_map(fn ($n) => ['key' => $n[0], 'type' => $n[1], 'name' => $n[2], 'config' => $n[3] ?? [], 'x' => $layout[$n[0]][0], 'y' => $layout[$n[0]][1]], $all),
            'edges' => array_map(fn ($e, $i) => ['key' => "t{$i}", 'from_node' => $e[0], 'to_node' => $e[1], 'outcome' => $e[2] ?? 'default', 'condition' => $e[3] ?? null, 'priority' => $e[4] ?? 100], $edges, array_keys($edges)),
            'settings' => $settings + ['initiators' => [], 'title_template' => $title, 'initiator_can_cancel' => true],
            'form_version_id' => $formVersion->id,
        ], $this->actor);
        $designer->publish($draft, $this->actor, 'Initial example route.');
        $this->command?->line("  {$key}: published");
    }

    /** Layered top-down layout so the designer opens readable. */
    protected function layout(array $nodes, array $edges): array
    {
        $depth = ['start' => 0];
        for ($pass = 0; $pass < count($nodes); $pass++) {
            foreach ($edges as $e) {
                if (isset($depth[$e[0]]) && ($e[2] ?? 'default') !== 'failure' && (! isset($depth[$e[1]]) || $depth[$e[1]] < $depth[$e[0]] + 1) && count($nodes) > $depth[$e[0]] + 1) {
                    $depth[$e[1]] = $depth[$e[0]] + 1;
                }
            }
        }
        $rows = [];
        $out = [];
        foreach ($nodes as $n) {
            $d = $depth[$n[0]] ?? count($nodes);
            $col = $rows[$d] = ($rows[$d] ?? -1) + 1;
            $out[$n[0]] = [$col * 280, $d * 140];
        }

        return $out;
    }
}
