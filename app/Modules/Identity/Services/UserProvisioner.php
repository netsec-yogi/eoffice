<?php

namespace App\Modules\Identity\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Directory\DirectoryUser;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\Designation;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Creates and refreshes local user records from directory entries.
 *
 * The directory is authoritative for identity attributes. Organisational
 * placement is taken from it only when it maps to a known department or
 * designation; otherwise the administrator's value is kept. New users get
 * the base role of their category and nothing else (IAM-006).
 */
class UserProvisioner
{
    public function __construct(private AuditLogger $audit) {}

    public function sync(DirectoryUser $entry): User
    {
        return DB::transaction(function () use ($entry) {
            $user = User::query()->where('username', $entry->username)->lockForUpdate()->first();

            if ($user?->isLocal()) {
                // A local account must never be taken over by a directory entry.
                throw new RuntimeException("Username '{$entry->username}' belongs to a local account.");
            }

            $creating = $user === null;
            $user ??= (new User)->forceFill(['username' => $entry->username, 'auth_source' => 'ldap']);
            $wasDisabled = (bool) $user->directory_disabled;

            $email = $entry->email;
            if ($email && User::query()->where('email', $email)->whereKeyNot($user->getKey())->exists()) {
                logger()->warning('Directory e-mail already used by another account; not copied.', ['username' => $entry->username]);
                $email = $user->email;
            }

            $user->forceFill([
                'name' => $entry->name,
                'email' => $email,
                'phone' => $entry->phone ?? $user->phone,
                'employee_id' => $this->uniqueEmployeeId($entry, $user),
                'category' => $entry->category,
                'ldap_dn' => $entry->dn,
                'directory_disabled' => $entry->disabled,
                'last_synced_at' => now(),
            ]);

            if ($entry->departmentCode && ($departmentId = Department::query()->where('code', $entry->departmentCode)->value('id'))) {
                $user->department_id = $departmentId;
            }
            if ($entry->designation && ($designationId = $this->designationId($entry->designation))) {
                $user->designation_id = $designationId;
            }

            $user->save();

            if ($creating) {
                $roles = Role::query()->whereIn('name', config("directory.default_roles.{$entry->category}", []))->get();
                $user->syncRoles($roles);
                $this->audit->record('identity.user.provisioned', $user, ['category' => $entry->category, 'roles' => $roles->pluck('name')->all()], actor: null);
            } elseif ($wasDisabled !== $entry->disabled) {
                $this->audit->record('identity.user.directory_status_changed', $user, ['directory_disabled' => $entry->disabled], actor: null);
            }

            return $user;
        });
    }

    private function designationId(string $value): ?int
    {
        return Designation::query()
            ->where('code', $value)
            ->orWhereRaw('lower(name) = ?', [strtolower($value)])
            ->value('id');
    }

    private function uniqueEmployeeId(DirectoryUser $entry, User $user): ?string
    {
        if (! $entry->employeeId) {
            return $user->employee_id;
        }
        $taken = User::query()->where('employee_id', $entry->employeeId)->whereKeyNot($user->getKey())->exists();

        return $taken ? $user->employee_id : $entry->employeeId;
    }
}
