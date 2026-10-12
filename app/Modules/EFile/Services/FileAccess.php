<?php

namespace App\Modules\EFile\Services;

use App\Modules\EFile\Models\EFile;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who may read a file (record-level authorization, Brief §10.4/§17.1).
 *
 * Always: the opener, the custodian, everyone the file has moved between,
 * holders of an access grant in force, and people with a task on a workflow
 * the file was routed through. Additionally, by classification:
 *   public       every member of staff and faculty
 *   internal     members of the owning department and its sub-units
 *   confidential nobody else
 *   restricted   nobody else
 * Administrators get no blanket access. Records officers see register
 * metadata (EFileController), not contents.
 *
 * `allows()` and `scope()` must stay equivalent; FileAccessTest checks both.
 */
class FileAccess
{
    public function allows(User $user, EFile $file): bool
    {
        if ($file->status === 'destroyed') {
            return false;
        }

        return $this->scope(EFile::query()->whereKey($file->id), $user)->exists();
    }

    public function scope(Builder $query, User $user): Builder
    {
        $departments = $user->department_id ? Department::query()->find($user->department_id)?->descendantIds() ?? [] : [];
        $staff = in_array($user->category, ['faculty', 'staff'], true);

        return $query->where('efiles.status', '!=', 'destroyed')->where(function (Builder $q) use ($user, $departments, $staff) {
            $q->where('efiles.opened_by', $user->id)
                ->orWhere('efiles.custodian_id', $user->id)
                ->orWhereExists(fn ($s) => $s->from('efile_movements')->whereColumn('efile_movements.efile_id', 'efiles.id')
                    ->where(fn ($m) => $m->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id)))
                ->orWhereExists(fn ($s) => $s->from('efile_access_grants')->whereColumn('efile_access_grants.efile_id', 'efiles.id')
                    ->where('user_id', $user->id)->whereNull('revoked_at')
                    ->where(fn ($g) => $g->whereNull('expires_at')->orWhere('expires_at', '>', now())))
                ->orWhereExists(fn ($s) => $s->from('workflow_instances')
                    ->where('workflow_instances.subject_type', 'efile')->whereColumn('workflow_instances.subject_id', 'efiles.id')
                    ->whereExists(fn ($t) => $t->from('workflow_tasks')->whereColumn('workflow_tasks.workflow_instance_id', 'workflow_instances.id')
                        ->where(fn ($a) => $a->where('assignee_id', $user->id)->orWhere('principal_id', $user->id))));
            if ($staff) {
                $q->orWhere('efiles.classification', 'public');
            }
            if ($departments) {
                $q->orWhere(fn ($d) => $d->where('efiles.classification', 'internal')->whereIn('efiles.department_id', $departments));
            }
        });
    }

    /** May $user write on the file right now (note, attach, send, close)? */
    public function canAct(User $user, EFile $file): bool
    {
        return $file->isOpen() && $file->custodian_id === $user->id
            && ! DB::table('efile_movements')->where('efile_id', $file->id)->where('status', 'in_transit')->exists();
    }
}
