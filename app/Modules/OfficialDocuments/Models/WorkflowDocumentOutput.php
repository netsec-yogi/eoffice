<?php

namespace App\Modules\OfficialDocuments\Models;

use App\Modules\Workflow\Models\WorkflowDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Workflow X produces official document Y" and the terms: when, for whom, released how (DOC-003). */
class WorkflowDocumentOutput extends Model
{
    public const GROUPS = [
        'applicant' => 'Applicant',
        'participants' => 'Officers who decided on the request',
        'department' => 'Authorised staff of the request\'s department',
        'records' => 'Record-keeping officers',
        'administrators' => 'Administrators holding the explicit permission',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'required_steps' => 'array', 'condition' => 'array', 'download_groups' => 'array', 'confidential' => 'boolean', 'requires_signature' => 'boolean'];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PdfTemplate::class, 'pdf_template_id');
    }
}
