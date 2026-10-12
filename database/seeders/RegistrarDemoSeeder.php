<?php

namespace Database\Seeders;

use App\Modules\Identity\Models\Authority;
use App\Modules\Identity\Models\User;
use App\Modules\Registrar\Models\LeaveLedgerEntry;
use App\Modules\Registrar\Models\LeaveType;
use App\Modules\Registrar\Services\LeaveService;
use Illuminate\Database\Seeder;

/** DEVELOPMENT ONLY: synthetic leave credits and office holders for the Registrar workflows. */
class RegistrarDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }
        $admin = User::query()->where('username', 'rverma')->first();
        $hod = User::query()->where('username', 'pkumar')->first();
        if (! $admin || ! $hod) {
            $this->command?->warn('Run DemoDataSeeder first.');

            return;
        }
        $admin->assignRole(['registrar_staff', 'finance_staff', 'rti_cell']);
        Authority::query()->where('code', 'RTI_FAA')->first()?->assignments()->firstOrCreate(['user_id' => $hod->id], ['starts_on' => now()->subYear()->toDateString()]);

        $leave = app(LeaveService::class);
        foreach (User::query()->whereIn('category', ['faculty', 'staff'])->get() as $user) {
            foreach (['CL' => 8, 'EL' => 30, 'HPL' => 20] as $code => $days) {
                $type = LeaveType::query()->where('code', $code)->first();
                if ($type?->is_active && LeaveLedgerEntry::query()->where(['user_id' => $user->id, 'leave_type_id' => $type->id])->doesntExist()) {
                    $leave->post($user, $type, 'credit', $days, 'Synthetic opening balance (development data)', $admin);
                }
            }
        }
        $this->command?->info('Registrar demo data loaded.');
    }
}
