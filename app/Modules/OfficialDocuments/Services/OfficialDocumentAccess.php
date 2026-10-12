<?php

namespace App\Modules\OfficialDocuments\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Permissions;
use App\Modules\OfficialDocuments\Models\OfficialDocument;
use App\Modules\Workflow\Models\WorkflowAction;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may download an official document (DOC-004, §9). Default: nobody
 * until the request is completed and approved and the document generated;
 * then only the groups the output allows. Confidential outputs narrow that
 * to the applicant, the officers who decided, and holders of the
 * confidential permission. No administrator role implies access.
 */
class OfficialDocumentAccess
{
    public function canDownload(User $user, OfficialDocument $doc): bool
    {
        $doc->loadMissing('instance', 'output');
        $instance = $doc->instance;
        if ($instance->status !== 'completed' || $instance->outcome !== 'approved') {
            return false;
        }
        if ($doc->status === 'superseded') {
            // Earlier issues stay with the record keepers.
            return $user->can(Permissions::OFFICIAL_DOCUMENTS_RECORDS) && (! $doc->output->confidential || $user->can(Permissions::OFFICIAL_DOCUMENTS_CONFIDENTIAL));
        }
        if (! in_array($doc->status, OfficialDocument::RELEASED, true)) {
            return false;
        }

        return in_array(true, array_map(fn ($g) => $this->inGroup($user, $doc, $g), $this->groups($doc)), true);
    }

    /** @return list<string> */
    public function groups(OfficialDocument $doc): array
    {
        $groups = $doc->output->download_groups ?: ['applicant'];

        return $doc->output->confidential ? array_values(array_intersect($groups, ['applicant', 'participants'])) + [99 => 'confidential'] : $groups;
    }

    private function inGroup(User $user, OfficialDocument $doc, string $group): bool
    {
        $instance = $doc->instance;

        return match ($group) {
            'applicant' => $instance->initiator_id === $user->id,
            'participants' => WorkflowAction::query()->where('workflow_instance_id', $instance->id)->whereIn('action', ['approved', 'rejected', 'returned'])
                ->where(fn ($q) => $q->where('actor_id', $user->id)->orWhere('on_behalf_of_id', $user->id))->exists(),
            'department' => $user->can(Permissions::OFFICIAL_DOCUMENTS_DEPARTMENT) && $user->department_id !== null && $user->department_id === $instance->department_id,
            'records' => $user->can(Permissions::OFFICIAL_DOCUMENTS_RECORDS),
            'administrators' => $user->can(Permissions::OFFICIAL_DOCUMENTS_ALL),
            'confidential' => $user->can(Permissions::OFFICIAL_DOCUMENTS_CONFIDENTIAL),
            default => false,
        };
    }

    /**
     * Documents worth listing for a user: their own requests, and requests
     * they decided on. Each row is still checked with canDownload().
     */
    public function listable(User $user): Builder
    {
        return OfficialDocument::query()->whereNotIn('status', ['archived'])
            ->whereHas('instance', fn ($q) => $q->where('initiator_id', $user->id)
                ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('workflow_actions')->whereColumn('workflow_actions.workflow_instance_id', 'workflow_instances.id')
                    ->where(fn ($w) => $w->where('actor_id', $user->id)->orWhere('on_behalf_of_id', $user->id))->whereIn('action', ['approved', 'rejected', 'returned'])));
    }
}
