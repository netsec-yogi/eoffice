<?php

namespace Database\Seeders;

use App\Modules\EFile\Models\FileCategory;
use App\Modules\EFile\Models\RetentionSchedule;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Forms\FormService;
use App\Modules\Workflow\Graph\WorkflowDesigner;
use App\Modules\Workflow\Models\WorkflowDefinition;
use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT / EXAMPLE: retention schedules, file categories and a file
 * approval workflow. The retention periods are placeholders, not the
 * institute's records schedule (docs/ASSUMPTIONS_AND_DECISIONS.md).
 */
class EFileDemoSeeder extends Seeder
{
    public function run(): void
    {
        $schedules = collect([
            ['GEN-5', 'General correspondence (placeholder: 5 years, then review)', 5, 'review'],
            ['FIN-10', 'Financial (placeholder: 10 years, then destroy)', 10, 'destroy'],
            ['PERM', 'Policy and establishment (permanent)', null, 'transfer'],
        ])->mapWithKeys(fn ($s) => [$s[0] => RetentionSchedule::query()->firstOrCreate(['code' => $s[0]], ['name' => $s[1], 'retain_years' => $s[2], 'action' => $s[3]])]);

        foreach ([
            ['GEN', 'General administration', 'GEN-5', 'internal'],
            ['PUR', 'Purchase and procurement', 'FIN-10', 'internal'],
            ['EST', 'Establishment and personnel', 'PERM', 'confidential'],
            ['ACAD', 'Academic matters', 'GEN-5', 'internal'],
        ] as [$code, $name, $schedule, $class]) {
            FileCategory::query()->firstOrCreate(['code' => $code], ['name' => $name, 'numbering_pattern' => '{DEPT}/{CAT}/{YYYY}/{SEQ:4}', 'default_classification' => $class, 'retention_schedule_id' => $schedules[$schedule]->id]);
        }

        $admin = User::role('system_admin')->first();
        if (! $admin || WorkflowDefinition::query()->where('key', 'file-approval')->exists()) {
            return;
        }
        $forms = app(FormService::class);
        $template = $forms->create(['key' => 'file-approval', 'name' => 'File approval'], $admin);
        $form = $template->versions()->first();
        $forms->saveDraft($form, ['sections' => [['key' => 'proposal', 'title' => 'Proposal', 'fields' => [
            ['key' => 'proposal', 'type' => 'textarea', 'label' => 'What is to be approved', 'required' => true, 'help' => 'Refer to the notes on the file, e.g. "as proposed at note 4".'],
            ['key' => 'amount', 'type' => 'decimal', 'label' => 'Financial implication (₹), if any', 'min' => 0],
        ]]]], $admin);
        $form = $forms->publish($form, $admin);

        $designer = app(WorkflowDesigner::class);
        $definition = $designer->create(['key' => 'file-approval', 'name' => 'File approval (HoD → Registrar)', 'description' => 'Route a file for formal approval. Example route: confirm with the Registrar.', 'category' => 'file_approval', 'numbering_pattern' => 'FAPP/{YYYY}/{SEQ:5}'], $admin);
        $draft = $definition->versions()->first();
        $designer->saveDraft($draft, [
            'nodes' => [
                ['key' => 'start', 'type' => 'start', 'name' => 'Submitted', 'x' => 0, 'y' => 0],
                ['key' => 'hod', 'type' => 'approval', 'name' => 'HoD recommendation', 'config' => ['assignees' => [['type' => 'authority', 'authority' => 'HOD', 'department' => 'initiator']], 'labels' => ['approve' => 'Recommend']], 'x' => 0, 'y' => 140],
                ['key' => 'registrar', 'type' => 'approval', 'name' => 'Registrar approval', 'config' => ['assignees' => [['type' => 'authority', 'authority' => 'REGISTRAR']]], 'x' => 0, 'y' => 280],
                ['key' => 'ok', 'type' => 'end', 'name' => 'Approved', 'config' => ['outcome' => 'approved'], 'x' => 0, 'y' => 420],
                ['key' => 'no', 'type' => 'end', 'name' => 'Not approved', 'config' => ['outcome' => 'rejected'], 'x' => 280, 'y' => 420],
            ],
            'edges' => [
                ['from_node' => 'start', 'to_node' => 'hod', 'outcome' => 'default'],
                ['from_node' => 'hod', 'to_node' => 'registrar', 'outcome' => 'approve'],
                ['from_node' => 'hod', 'to_node' => 'no', 'outcome' => 'reject'],
                ['from_node' => 'registrar', 'to_node' => 'ok', 'outcome' => 'approve'],
                ['from_node' => 'registrar', 'to_node' => 'no', 'outcome' => 'reject'],
            ],
            'settings' => ['initiators' => ['categories' => ['faculty', 'staff']], 'title_template' => 'File approval: {{ proposal }}', 'fallback' => ['type' => 'authority', 'authority' => 'REGISTRAR']],
            'form_version_id' => $form->id,
        ], $admin);
        $designer->publish($draft, $admin, 'Example route for routing files for approval.');
    }
}
