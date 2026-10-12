<?php

namespace Database\Seeders;

use App\Modules\Identity\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $new = [];
        foreach (Permissions::all() as $name) {
            if (! Permission::query()->where(['name' => $name, 'guard_name' => 'web'])->exists()) {
                $new[] = $name;
            }
            Permission::findOrCreate($name, 'web');
        }

        // Create missing roles with their seed permissions. Existing roles keep
        // whatever administrators changed -- except system_admin, which always
        // holds everything.
        foreach (Permissions::roles() as $name => $permissions) {
            $role = Role::query()->where(['name' => $name, 'guard_name' => 'web'])->first();
            if ($role === null) {
                $role = Role::create(['name' => $name, 'guard_name' => 'web']);
                $role->syncPermissions($permissions === '*' ? Permissions::wildcard() : $permissions);
            } elseif ($permissions === '*') {
                $role->syncPermissions(Permissions::wildcard());
            } elseif ($added = array_intersect($permissions, $new)) {
                // Permissions introduced by a later release reach existing roles
                // once; what administrators removed earlier stays removed.
                $role->givePermissionTo($added);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
