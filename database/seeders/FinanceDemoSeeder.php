<?php

namespace Database\Seeders;

use App\Modules\Finance\Models\BudgetEntry;
use App\Modules\Finance\Models\BudgetHead;
use App\Modules\Finance\Models\ProcurementRuleSet;
use App\Modules\Finance\Models\Vendor;
use App\Modules\Finance\Services\BudgetService;
use App\Modules\Finance\Services\ProcurementRules;
use App\Modules\Identity\Models\Committee;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT ONLY: synthetic budget, vendors and a procurement rule set.
 * The rule set's limits are invented to exercise every branch; they are
 * NOT the institute's rules and say so on every screen that shows them.
 */
class FinanceDemoSeeder extends Seeder
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
        $admin->assignRole(['purchase_officer', 'finance_staff', 'finance_manager']);
        $committee = Committee::query()->where('code', 'PURCHASE_COMMITTEE')->first();
        foreach ([$hod, $admin] as $member) {
            $committee?->members()->firstOrCreate(['user_id' => $member->id], ['role' => 'member', 'starts_on' => now()->subYear()->toDateString()]);
        }

        $rules = app(ProcurementRules::class);
        if (! ProcurementRuleSet::current()) {
            $set = $rules->saveDraft(null, [
                'title' => 'DEMO — not institutional rules',
                'authority' => 'Synthetic limits for development. Finance must replace these with the limits in force (GFR / DoE orders / the institute\'s delegation of financial powers) before go-live.',
                'bands' => [
                    ['up_to' => 25000, 'method' => 'direct', 'label' => 'Demo band A', 'quotations' => 0, 'committee' => false],
                    ['up_to' => 250000, 'method' => 'quotations', 'label' => 'Demo band B', 'quotations' => 3, 'committee' => true],
                    ['up_to' => 2500000, 'method' => 'limited_tender', 'label' => 'Demo band C', 'quotations' => 3, 'committee' => true],
                    ['up_to' => null, 'method' => 'open_tender', 'label' => 'Demo band D', 'quotations' => 3, 'committee' => true],
                ],
            ], $admin);
            $rules->publish($set, $admin);
        }

        $budget = app(BudgetService::class);
        $year = BudgetService::financialYear();
        foreach ([['CSE-CONS', 'CSE consumables', 'CSE', 300000], ['CSE-EQP', 'CSE equipment', 'CSE', 1500000], ['CC-IT', 'Computer Centre IT equipment', 'CC', 2000000]] as [$code, $name, $dept, $amount]) {
            $head = BudgetHead::query()->firstOrCreate(['code' => $code, 'financial_year' => $year], ['name' => $name, 'department_id' => Department::query()->where('code', $dept)->value('id'), 'is_active' => true]);
            if (BudgetEntry::query()->where('budget_head_id', $head->id)->doesntExist()) {
                $budget->post($head, 'allocation', $amount, 'Synthetic allocation (development data)', null, $admin);
            }
        }

        foreach ([
            ['ACME', 'Acme Lab Supplies (synthetic)', '09AAAAA0000A1Z5', 'AAAAA0000A', 'acme@vendors.test', '000123456789', 'TEST0000001'],
            ['ZENITH', 'Zenith Computers (synthetic)', '09BBBBB0000B1Z5', 'BBBBB0000B', 'zenith@vendors.test', '000987654321', 'TEST0000002'],
            ['ORBIT', 'Orbit Traders (synthetic)', '09CCCCC0000C1Z5', 'CCCCC0000C', 'orbit@vendors.test', '000555666777', 'TEST0000003'],
        ] as [$code, $name, $gstin, $pan, $email, $account, $ifsc]) {
            $vendor = Vendor::query()->firstOrCreate(['code' => $code], ['name' => $name, 'gstin' => $gstin, 'pan' => $pan, 'email' => $email, 'bank_account' => $account, 'ifsc' => $ifsc, 'address' => 'Synthetic address, Test City', 'is_active' => true]);
            if (! $vendor->user_id) {
                $user = User::query()->firstOrNew(['username' => 'v-'.strtolower($code)]);
                // Same documented development password as the demo directory accounts.
                $user->forceFill(['name' => $name, 'email' => $email, 'category' => 'vendor', 'auth_source' => 'local', 'password' => 'Dev-Password-2026', 'is_active' => true])->save();
                $vendor->forceFill(['user_id' => $user->id])->save();
            }
        }
        $this->command?->info('Finance demo data loaded (DEMO procurement rules — not institutional). Vendor sign-ins: v-acme, v-zenith, v-orbit.');
    }
}
