<?php

namespace App\Modules\Workflow\Forms;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Engine\WorkflowException;
use App\Modules\Workflow\Models\FormTemplate;
use App\Modules\Workflow\Models\FormVersion;
use Illuminate\Support\Facades\DB;

/** Lifecycle of form templates: draft, validate, publish, new draft (FORM-004). */
class FormService
{
    public function __construct(private FormSchemaValidator $validator, private AuditLogger $audit) {}

    public function create(array $data, User $by): FormTemplate
    {
        return DB::transaction(function () use ($data, $by) {
            $template = new FormTemplate($data);
            $template->forceFill(['created_by' => $by->id])->save();
            $this->newVersion($template, 1, ['sections' => [['key' => 'details', 'title' => 'Details', 'fields' => []]]], $by);
            $this->audit->record('form.created', $template, $template->only('key', 'name'), actor: $by);

            return $template;
        });
    }

    public function saveDraft(FormVersion $version, array $schema, User $by): FormVersion
    {
        if ($version->status !== 'draft') {
            throw new WorkflowException('Published form versions cannot be edited. Open a new draft.', 409);
        }
        $version->forceFill(['schema' => $schema])->save();
        $this->audit->record('form.version.saved', $version, ['sections' => count($schema['sections'] ?? [])], actor: $by);

        return $version;
    }

    /** @return list<string> */
    public function problems(FormVersion $version): array
    {
        return $this->validator->problems($version->schema);
    }

    public function publish(FormVersion $version, User $by): FormVersion
    {
        return DB::transaction(function () use ($version, $by) {
            $version = FormVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($version->status !== 'draft') {
                throw new WorkflowException('This form version is already published.', 409);
            }
            if ($problems = $this->problems($version)) {
                throw new WorkflowException('Fix before publishing: '.$problems[0].(count($problems) > 1 ? ' (and '.(count($problems) - 1).' more)' : ''), 422, ['schema' => $problems[0]]);
            }
            FormVersion::query()->where('form_template_id', $version->form_template_id)->where('status', 'published')
                ->update(['status' => 'retired', 'updated_at' => now()]);
            $version->forceFill(['status' => 'published', 'published_at' => now(), 'published_by' => $by->id])->save();
            $this->audit->record('form.version.published', $version, ['version' => $version->version, 'checksum' => hash('sha256', json_encode($version->schema))], actor: $by);

            return $version;
        });
    }

    public function draft(FormTemplate $template, User $by): FormVersion
    {
        return DB::transaction(function () use ($template, $by) {
            FormTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $latest = FormVersion::query()->where('form_template_id', $template->id)->orderByDesc('version')->firstOrFail();
            if ($latest->status === 'draft') {
                return $latest;
            }

            return $this->newVersion($template, $latest->version + 1, $latest->schema, $by);
        });
    }

    private function newVersion(FormTemplate $template, int $number, array $schema, User $by): FormVersion
    {
        $version = new FormVersion;
        $version->forceFill([
            'form_template_id' => $template->id,
            'version' => $number,
            'status' => 'draft',
            'schema' => $schema,
            'created_by' => $by->id,
        ])->save();

        return $version;
    }
}
