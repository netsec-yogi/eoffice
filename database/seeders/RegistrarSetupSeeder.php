<?php

namespace Database\Seeders;

use App\Modules\Identity\Models\Authority;
use App\Modules\Registrar\Models\CertificateTemplate;
use App\Modules\Registrar\Models\LeaveType;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * PLACEHOLDER policy data for the Registrar workflows. Leave entitlements,
 * notice periods and certificate wording are the institute's to set
 * (Admin -> Leave types / Certificate templates); nothing here is a rule.
 */
class RegistrarSetupSeeder extends Seeder
{
    public function run(): void
    {
        $note = 'Placeholder values; confirm with the Registrar against the applicable leave rules.';
        foreach ([
            ['CL', 'Casual leave', true, false, true, 5, null, null],
            ['EL', 'Earned leave', true, true, false, null, 7, null],
            ['HPL', 'Half pay leave', true, true, false, null, null, 3],
            ['DL', 'Duty leave', false, false, false, null, null, null],
            ['SL', 'Station leave (leaving headquarters)', false, true, false, null, null, null],
        ] as [$code, $name, $balance, $holidays, $half, $max, $notice, $certAfter]) {
            LeaveType::query()->firstOrCreate(['code' => $code], [
                'name' => $name, 'description' => $note, 'has_balance' => $balance, 'counts_holidays' => $holidays, 'allow_half_day' => $half,
                'max_days_per_application' => $max, 'min_notice_days' => $notice, 'certificate_after_days' => $certAfter,
            ]);
        }

        $sign = 'This certificate is issued on the request of the employee.';
        foreach ([
            ['NOC-PASSPORT', 'NOC for passport', 'No Objection Certificate', "This is to certify that {{ holder.name }}, {{ holder.designation }}, {{ holder.department }} (Employee ID {{ holder.employee_id }}), is an employee of this Institute.\n\nThe Institute has no objection to the employee obtaining an ordinary passport ({{ passport_purpose }}).", 'NOC/PP/{YYYY}/{SEQ:4}'],
            ['NOC-HS', 'NOC for higher studies', 'No Objection Certificate', "This is to certify that {{ holder.name }}, {{ holder.designation }}, {{ holder.department }}, is an employee of this Institute.\n\nThe Institute has no objection to the employee pursuing {{ course }} at {{ institution }} ({{ study_mode }}), subject to the applicable service rules.", 'NOC/HS/{YYYY}/{SEQ:4}'],
            ['NOC-OE', 'NOC for outside employment', 'No Objection Certificate', "This is to certify that {{ holder.name }}, {{ holder.designation }}, {{ holder.department }}, is an employee of this Institute.\n\nThe Institute has no objection to the employee applying to {{ organisation }} for the post of {{ post }}. In case of selection, the employee will be relieved as per rules.", 'NOC/OE/{YYYY}/{SEQ:4}'],
            ['EXP', 'Experience certificate', 'Experience Certificate', "This is to certify that {{ holder.name }} (Employee ID {{ holder.employee_id }}) has been working in this Institute as {{ holder.designation }}, {{ holder.department }}, {{ service_period }}.\n\nThe certificate is issued for the purpose of {{ purpose }}.", 'EXP/{YYYY}/{SEQ:4}'],
        ] as [$code, $name, $title, $body, $pattern]) {
            CertificateTemplate::query()->firstOrCreate(['code' => $code], ['name' => $name, 'title' => $title, 'body' => $body, 'numbering_pattern' => $pattern, 'footer' => $sign]);
        }

        foreach ([['DIRECTOR', 'Director', 'institute'], ['REGISTRAR', 'Registrar', 'institute'], ['FINANCE_OFFICER', 'Finance Officer', 'institute'], ['RTI_FAA', 'RTI First Appellate Authority', 'institute'], ['HOD', 'Head of Department', 'department']] as [$code, $name, $scope]) {
            Authority::query()->firstOrCreate(['code' => $code], ['name' => $name, 'scope' => $scope]);
        }
        foreach (['registrar_staff', 'finance_staff', 'rti_cell'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
