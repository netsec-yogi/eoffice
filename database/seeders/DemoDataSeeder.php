<?php

namespace Database\Seeders;

use App\Modules\ComputerCentre\Models\ItAsset;
use App\Modules\ComputerCentre\Models\SoftwareTitle;
use App\Modules\ComputerCentre\Services\AssetService;
use App\Modules\Identity\Models\Authority;
use App\Modules\Identity\Models\Committee;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\Designation;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT ONLY. Synthetic organisation matching docs/../docker/ldap/bootstrap.ldif.
 * Department names, authorities and committees are illustrative, not the
 * institute's actual structure. Refuses to run in production.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoDataSeeder refuses to run in production.');

            return;
        }

        $institute = Department::query()->updateOrCreate(['code' => 'INST'], ['name' => 'Institute', 'type' => 'administrative']);
        $departments = collect([
            ['CSE', 'Computer Science and Engineering', 'academic'],
            ['CC', 'Computer Centre', 'centre'],
            ['REG', 'Registrar Office', 'office'],
            ['FIN', 'Finance Office', 'office'],
            ['PUR', 'Purchase Office', 'office'],
            ['EST', 'Estate Office', 'office'],
        ])->mapWithKeys(fn ($d) => [$d[0] => Department::query()->updateOrCreate(['code' => $d[0]], ['name' => $d[1], 'type' => $d[2], 'parent_id' => $institute->id])]);

        foreach ([['PROF', 'Professor', 10], ['HOD', 'Head of Department', 12], ['SYSADMIN', 'System Administrator', 6], ['AR', 'Assistant Registrar', 8], ['REGISTRAR', 'Registrar', 14], ['DIRECTOR', 'Director', 20], ['JA', 'Junior Assistant', 3]] as [$code, $name, $level]) {
            Designation::query()->updateOrCreate(['code' => $code], ['name' => $name, 'level' => $level]);
        }

        $authorities = collect([
            ['HOD', 'Head of Department', 'department'],
            ['CC_HEAD', 'Head, Computer Centre', 'institute'],
            ['REGISTRAR', 'Registrar', 'institute'],
            ['DIRECTOR', 'Director', 'institute'],
            ['FINANCE_OFFICER', 'Finance Officer', 'institute'],
        ])->mapWithKeys(fn ($a) => [$a[0] => Authority::query()->updateOrCreate(['code' => $a[0]], ['name' => $a[1], 'scope' => $a[2]])]);

        // Users mirror the development directory so they can sign in.
        $people = [
            ['asharma', 'Anita Sharma', 'asharma@institute.test', 'faculty', 'CSE', 'PROF', 'E1001'],
            ['rverma', 'Rahul Verma', 'rverma@institute.test', 'staff', 'CC', 'SYSADMIN', 'E1002'],
            ['pkumar', 'Priya Kumar', 'pkumar@institute.test', 'faculty', 'CSE', 'HOD', 'E1003'],
            ['s2024001', 'Karan Singh', 's2024001@students.institute.test', 'student', 'CSE', null, '2024IMT001'],
        ];
        $users = [];
        foreach ($people as [$username, $name, $email, $category, $dept, $designation, $empId]) {
            $user = User::query()->firstOrNew(['username' => $username]);
            $user->forceFill([
                'username' => $username, 'name' => $name, 'email' => $email, 'category' => $category, 'employee_id' => $empId,
                'auth_source' => 'ldap', 'department_id' => $departments[$dept]->id,
                'designation_id' => $designation ? Designation::query()->where('code', $designation)->value('id') : null,
            ])->save();
            $user->assignRole($category === 'student' ? 'student' : 'employee');
            $users[$username] = $user;
        }
        $users['asharma']->forceFill(['reports_to_id' => $users['pkumar']->id])->save();

        $assign = function (string $authority, User $user, ?Department $department = null) use ($authorities) {
            $authorities[$authority]->assignments()->firstOrCreate(
                ['user_id' => $user->id, 'department_id' => $department?->id],
                ['starts_on' => now()->subYear()->toDateString()],
            );
        };
        $assign('HOD', $users['pkumar'], $departments['CSE']);
        $assign('CC_HEAD', $users['rverma']);
        $assign('REGISTRAR', $users['pkumar']); // one person, two offices - deliberately, to exercise SoD

        $committee = Committee::query()->updateOrCreate(['code' => 'IT-PURCHASE'], ['name' => 'IT Purchase Committee', 'department_id' => $departments['CC']->id]);
        foreach (['asharma' => 'chair', 'pkumar' => 'member', 'rverma' => 'member'] as $username => $role) {
            $committee->members()->firstOrCreate(['user_id' => $users[$username]->id], ['role' => $role, 'starts_on' => now()->subYear()->toDateString()]);
        }

        $users['rverma']->assignRole(['cc_operator', 'records_officer']);
        $assign('FINANCE_OFFICER', $users['rverma']); // synthetic: one person, several offices

        // Synthetic Computer Centre register.
        $assets = app(AssetService::class);
        foreach ([['LAP-2026-001', 'laptop', 'Lenovo', 'ThinkPad E14', 'PF3XK01'], ['LAP-2026-002', 'laptop', 'Dell', 'Latitude 5440', 'DL5440A'], ['MON-2026-001', 'monitor', 'LG', '24MP400', 'LG24A9'], ['PRN-2026-001', 'printer', 'HP', 'LaserJet M211', 'HPM211X']] as [$no, $cat, $make, $model, $serial]) {
            if (! ItAsset::query()->where('asset_number', $no)->exists()) {
                $assets->register(['asset_number' => $no, 'category' => $cat, 'manufacturer' => $make, 'model' => $model, 'serial_number' => $serial, 'location' => 'CC store', 'warranty_until' => now()->addYear()->toDateString(), 'purchase_date' => now()->subMonths(3)->toDateString()], $users['rverma']);
            }
        }
        foreach ([['matlab', 'MATLAB (campus)', 'MathWorks', 'per_seat', 25], ['office', 'Office suite', 'Example Corp', 'subscription', 200], ['spss', 'SPSS Statistics', 'IBM', 'per_seat', 2]] as [$code, $name, $publisher, $type, $seats]) {
            SoftwareTitle::query()->firstOrCreate(['code' => $code], ['name' => $name, 'publisher' => $publisher, 'licence_type' => $type, 'seats' => $seats, 'expires_on' => now()->addMonths(10)->toDateString(), 'is_active' => true]);
        }

        $this->command?->info('Demo organisation loaded. Directory password for all demo accounts: Dev-Password-2026');
    }
}
