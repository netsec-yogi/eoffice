<?php

namespace Database\Seeders;

use App\Modules\Identity\Models\User;
use Database\Seeders\Concerns\DefinesWorkflows;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Administration and Registrar workflows (Phase 5) as configuration. Routes,
 * thresholds and deadlines are EXAMPLES to be confirmed by the Registrar;
 * each description says so.
 */
class RegistrarWorkflowSeeder extends Seeder
{
    use DefinesWorkflows;

    private const NOTE = ' (Example route: confirm with the Registrar.)';

    private const HOD = ['type' => 'authority', 'authority' => 'HOD', 'department' => 'initiator'];

    public function run(): void
    {
        $this->actor = User::role('system_admin')->first() ?? throw new RuntimeException('Seed a system administrator first.');
        $this->call(RegistrarSetupSeeder::class);

        $this->leave();
        $this->leaveCancellation();
        $this->tour();
        $this->claim('tada-claim', 'TA/DA claim', 'tour', 'my_tours', 'claim.tour');
        $this->ltc();
        $this->claim('ltc-claim', 'LTC claim', 'ltc', 'my_ltc', 'claim.ltc');
        $this->medical();
        $this->certificates();
        $this->circularApproval();
        $this->rti();
        $this->rtiAppeal();
    }

    private function staff(): array
    {
        return ['initiators' => ['categories' => ['faculty', 'staff']], 'fallback' => self::HOD];
    }

    private function approval(string $key, string $name, array $assignee, array $extra = []): array
    {
        return [$key, 'approval', $name, ['assignees' => [$assignee], 'actions' => ['approve', 'reject', 'return'], 'sla' => ['duration' => 3, 'unit' => 'working_days']] + $extra];
    }

    private function auto(string $key, string $name, string $action, array $settings = []): array
    {
        return [$key, 'automation', $name, ['action' => $action, 'settings' => $settings, 'on_blocked' => ['type' => 'role', 'role' => 'registrar_staff']]];
    }

    private function ends(): array
    {
        return [['done', 'end', 'Approved', ['outcome' => 'approved']], ['refused', 'end', 'Not approved', ['outcome' => 'rejected']]];
    }

    private function leave(): void
    {
        $this->define('leave', 'Leave application', 'Casual, earned, duty and other leave. Balance, overlaps and holidays are checked when you submit.'.self::NOTE, 'leave', [
            ['key' => 'leave_type', 'type' => 'select', 'label' => 'Kind of leave', 'required' => true, 'source' => 'leave_types'],
            ['key' => 'from_date', 'type' => 'date', 'label' => 'From', 'required' => true],
            ['key' => 'to_date', 'type' => 'date', 'label' => 'To', 'required' => true, 'min' => 'from_date'],
            ['key' => 'half_day', 'type' => 'checkbox', 'label' => 'Half day', 'placeholder' => 'Half a day only (single-day casual leave)'],
            ['key' => 'reason', 'type' => 'textarea', 'label' => 'Reason', 'required' => true],
            ['key' => 'leave_station', 'type' => 'checkbox', 'label' => 'Station', 'placeholder' => 'I will leave headquarters during this leave'],
            ['key' => 'address', 'type' => 'textarea', 'label' => 'Address and phone while away', 'required_if' => ['field' => 'leave_station', 'op' => 'eq', 'value' => true], 'visible_if' => ['field' => 'leave_station', 'op' => 'eq', 'value' => true]],
            ['key' => 'arrangement', 'type' => 'textarea', 'label' => 'Arrangement for work / classes during absence'],
            ['key' => 'certificate', 'type' => 'file', 'label' => 'Medical / supporting certificate', 'accept' => ['pdf', 'jpg', 'jpeg', 'png']],
        ], [
            $this->auto('reserve', 'Hold leave days', 'leave.reserve'),
            $this->approval('sanction', 'Sanctioning authority', ['type' => 'reporting_manager', 'levels' => 1], ['labels' => ['approve' => 'Sanction']]),
            ['long', 'decision', 'Long earned leave?'],
            $this->approval('registrar', 'Registrar', ['type' => 'authority', 'authority' => 'REGISTRAR']),
            $this->auto('debit', 'Debit leave balance', 'leave.approve'),
            ...$this->ends(),
        ], [
            ['start', 'reserve'], ['reserve', 'sanction', 'success'],
            ['sanction', 'long', 'approve'], ['sanction', 'refused', 'reject'],
            ['long', 'registrar', 'default', ['all' => [['field' => 'leave_type', 'op' => 'eq', 'value' => 'EL'], ['field' => 'days', 'op' => 'gt', 'value' => 30]]], 10],
            ['long', 'debit', 'default', null, 20],
            ['registrar', 'debit', 'approve'], ['registrar', 'refused', 'reject'],
            ['debit', 'done', 'success'],
        ], '{{ leave_type_name }}: {{ from_date }} to {{ to_date }}', $this->staff() + ['validator' => 'leave.application']);
    }

    private function leaveCancellation(): void
    {
        $this->define('leave-cancellation', 'Leave cancellation / curtailment', 'Cancel sanctioned leave, or return early. Unused days are credited back.'.self::NOTE, 'leave', [
            ['key' => 'leave', 'type' => 'select', 'label' => 'Leave', 'required' => true, 'source' => 'my_leaves'],
            ['key' => 'new_to_date', 'type' => 'date', 'label' => 'Return early: new last day of leave', 'help' => 'Leave empty to cancel the whole leave.'],
            ['key' => 'reason', 'type' => 'textarea', 'label' => 'Reason', 'required' => true],
        ], [
            $this->approval('sanction', 'Sanctioning authority', ['type' => 'reporting_manager', 'levels' => 1]),
            $this->auto('credit', 'Credit back unused days', 'leave.cancel'),
            ...$this->ends(),
        ], [['start', 'sanction'], ['sanction', 'credit', 'approve'], ['sanction', 'refused', 'reject'], ['credit', 'done', 'success']],
            'Leave change: {{ leave_summary }}', $this->staff() + ['validator' => 'leave.cancellation']);
    }

    private function tour(): void
    {
        $this->define('tour', 'Tour / travel authorisation', 'Approval to travel on duty, with an optional advance.'.self::NOTE, 'travel', [
            ['key' => 'purpose', 'type' => 'textarea', 'label' => 'Purpose', 'required' => true],
            ['key' => 'destination', 'type' => 'text', 'label' => 'Destination', 'required' => true],
            ['key' => 'from_date', 'type' => 'date', 'label' => 'Departure', 'required' => true],
            ['key' => 'to_date', 'type' => 'date', 'label' => 'Return', 'required' => true, 'min' => 'from_date'],
            ['key' => 'mode', 'type' => 'select', 'label' => 'Mode of travel', 'required' => true, 'options' => array_map(fn ($m) => ['value' => $m, 'label' => ucfirst($m)], ['rail', 'air', 'road', 'own vehicle'])],
            ['key' => 'estimated_cost', 'type' => 'decimal', 'label' => 'Estimated cost (₹)', 'required' => true, 'min' => 0],
            ['key' => 'funding', 'type' => 'select', 'label' => 'Charged to', 'required' => true, 'options' => [['value' => 'institute', 'label' => 'Institute'], ['value' => 'project', 'label' => 'Project'], ['value' => 'organiser', 'label' => 'Organiser']]],
            ['key' => 'advance_requested', 'type' => 'decimal', 'label' => 'Advance requested (₹)', 'min' => 0],
            ['key' => 'advance_sanctioned', 'type' => 'decimal', 'label' => 'Advance sanctioned (₹)', 'min' => 0, 'editable_at' => ['sanction']],
            ['key' => 'invitation', 'type' => 'file', 'label' => 'Invitation / supporting document', 'accept' => ['pdf', 'jpg', 'jpeg', 'png']],
        ], [
            $this->auto('record', 'Record journey', 'registrar.travel', ['step' => 'record_journey', 'kind' => 'tour']),
            $this->approval('hod', 'HoD recommendation', self::HOD, ['labels' => ['approve' => 'Recommend']]),
            $this->approval('sanction', 'Registrar sanction', ['type' => 'authority', 'authority' => 'REGISTRAR'], ['labels' => ['approve' => 'Sanction']]),
            $this->auto('approve', 'Mark journey approved', 'registrar.travel', ['step' => 'approve_journey', 'kind' => 'tour']),
            ...$this->ends(),
        ], [['start', 'record'], ['record', 'hod', 'success'], ['hod', 'sanction', 'approve'], ['hod', 'refused', 'reject'], ['sanction', 'approve', 'approve'], ['sanction', 'refused', 'reject'], ['approve', 'done', 'success']],
            'Tour: {{ destination }} ({{ from_date }} – {{ to_date }})', $this->staff() + ['validator' => 'travel.tour']);
    }

    private function ltc(): void
    {
        $this->define('ltc', 'Leave travel concession (LTC)', 'LTC journey for you and your dependants, within your block entitlement.'.self::NOTE, 'travel', [
            ['key' => 'purpose', 'type' => 'select', 'label' => 'LTC type', 'required' => true, 'options' => [['value' => 'hometown', 'label' => 'Home town'], ['value' => 'anywhere', 'label' => 'Anywhere in India']]],
            ['key' => 'destination', 'type' => 'text', 'label' => 'Destination', 'required' => true],
            ['key' => 'from_date', 'type' => 'date', 'label' => 'Departure', 'required' => true],
            ['key' => 'to_date', 'type' => 'date', 'label' => 'Return', 'required' => true, 'min' => 'from_date'],
            ['key' => 'travellers', 'type' => 'table', 'label' => 'Travelling', 'required' => true, 'max_rows' => 8, 'columns' => [
                ['key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true],
                ['key' => 'relation', 'type' => 'select', 'label' => 'Relation', 'required' => true, 'options' => array_map(fn ($r) => ['value' => $r, 'label' => ucfirst($r)], ['self', 'spouse', 'child', 'parent', 'other'])],
                ['key' => 'age', 'type' => 'number', 'label' => 'Age'],
            ]],
            ['key' => 'advance_requested', 'type' => 'decimal', 'label' => 'Advance requested (₹)', 'min' => 0],
            ['key' => 'advance_sanctioned', 'type' => 'decimal', 'label' => 'Advance sanctioned (₹)', 'min' => 0, 'editable_at' => ['sanction']],
            ['key' => 'leave_reference', 'type' => 'text', 'label' => 'Leave application reference', 'help' => 'LTC is availed during sanctioned leave.'],
        ], [
            $this->auto('record', 'Record journey', 'registrar.travel', ['step' => 'record_journey', 'kind' => 'ltc']),
            $this->approval('hod', 'HoD recommendation', self::HOD, ['labels' => ['approve' => 'Recommend']]),
            $this->approval('sanction', 'Registrar sanction', ['type' => 'authority', 'authority' => 'REGISTRAR'], ['labels' => ['approve' => 'Sanction']]),
            $this->auto('approve', 'Mark journey approved', 'registrar.travel', ['step' => 'approve_journey', 'kind' => 'ltc']),
            ...$this->ends(),
        ], [['start', 'record'], ['record', 'hod', 'success'], ['hod', 'sanction', 'approve'], ['hod', 'refused', 'reject'], ['sanction', 'approve', 'approve'], ['sanction', 'refused', 'reject'], ['approve', 'done', 'success']],
            'LTC: {{ destination }} ({{ from_date }} – {{ to_date }})', $this->staff() + ['validator' => 'travel.ltc']);
    }

    private function claimSteps(string $kind): array
    {
        return [
            $this->auto('record', 'Record claim', 'registrar.travel', ['step' => 'record_claim', 'kind' => $kind]),
            ['verify', 'verification', 'Finance verification', ['assignees' => [['type' => 'role', 'role' => 'finance_staff']], 'actions' => ['approve', 'reject', 'return'], 'labels' => ['approve' => 'Verified'], 'instructions' => 'Check the bills and enter the admissible amount. Return for clarification if needed.', 'sla' => ['duration' => 5, 'unit' => 'working_days']]],
            $this->approval('sanction', 'Finance Officer sanction', ['type' => 'authority', 'authority' => 'FINANCE_OFFICER'], ['labels' => ['approve' => 'Sanction']]),
            $this->auto('sanctioned', 'Record sanction', 'registrar.travel', ['step' => 'sanction_claim', 'kind' => $kind]),
            ['payment', 'verification', 'Payment', ['assignees' => [['type' => 'role', 'role' => 'finance_staff']], 'actions' => ['approve'], 'labels' => ['approve' => 'Paid'], 'instructions' => 'Enter the payment reference once the amount has been paid (or recovered).']],
            $this->auto('paid', 'Record payment', 'registrar.travel', ['step' => 'mark_paid', 'kind' => $kind]),
            ...$this->ends(),
        ];
    }

    private function claimEdges(): array
    {
        return [
            ['start', 'record'], ['record', 'verify', 'success'], ['verify', 'sanction', 'approve'], ['verify', 'refused', 'reject'],
            ['sanction', 'sanctioned', 'approve'], ['sanction', 'refused', 'reject'], ['sanctioned', 'payment', 'success'], ['payment', 'paid', 'approve'], ['paid', 'done', 'success'],
        ];
    }

    private function claimFinanceFields(): array
    {
        return [
            ['key' => 'admissible_amount', 'type' => 'decimal', 'label' => 'Admissible amount (₹)', 'min' => 0, 'editable_at' => ['verify']],
            ['key' => 'verification_remarks', 'type' => 'remark', 'label' => 'Verification remarks', 'editable_at' => ['verify']],
            ['key' => 'payment_reference', 'type' => 'text', 'label' => 'Payment / voucher reference', 'editable_at' => ['payment'], 'required' => true],
            ['key' => 'paid_on', 'type' => 'date', 'label' => 'Paid on', 'editable_at' => ['payment']],
        ];
    }

    private function claim(string $key, string $name, string $kind, string $source, string $validator): void
    {
        $this->define($key, $name, 'Claim expenses of an approved journey. The sanctioned advance is adjusted.'.self::NOTE, 'reimbursement', [
            ['key' => 'authorisation', 'type' => 'select', 'label' => 'Journey', 'required' => true, 'source' => $source],
            ['key' => 'bills', 'type' => 'table', 'label' => 'Expenses', 'required' => true, 'max_rows' => 40, 'columns' => [
                ['key' => 'date', 'type' => 'date', 'label' => 'Date', 'required' => true],
                ['key' => 'head', 'type' => 'select', 'label' => 'Head', 'required' => true, 'options' => array_map(fn ($h) => ['value' => $h, 'label' => ucfirst($h)], ['fare', 'lodging', 'local conveyance', 'daily allowance', 'registration', 'other'])],
                ['key' => 'details', 'type' => 'text', 'label' => 'Details'],
                ['key' => 'amount', 'type' => 'decimal', 'label' => 'Amount (₹)', 'required' => true],
            ]],
            ['key' => 'total', 'type' => 'calculated', 'label' => 'Total claimed (₹)', 'formula' => 'sum(bills.amount)'],
            ['key' => 'receipts', 'type' => 'files', 'label' => 'Tickets and receipts', 'required' => true, 'accept' => ['pdf', 'jpg', 'jpeg', 'png'], 'max_files' => 20],
            ['key' => 'declaration', 'type' => 'checkbox', 'label' => 'Declaration', 'placeholder' => 'The expenses were actually incurred and are not claimed elsewhere', 'required' => true],
            ...$this->claimFinanceFields(),
        ], $this->claimSteps($kind), $this->claimEdges(), "{$name}: {{ journey }}", $this->staff() + ['validator' => $validator]);
    }

    private function medical(): void
    {
        $this->define('medical-claim', 'Medical reimbursement', 'Reimbursement of medical expenses for you or your dependants.'.self::NOTE, 'reimbursement', [
            ['key' => 'patient', 'type' => 'select', 'label' => 'Patient', 'required' => true, 'options' => array_map(fn ($r) => ['value' => $r, 'label' => ucfirst($r)], ['self', 'spouse', 'child', 'parent'])],
            ['key' => 'patient_name', 'type' => 'text', 'label' => 'Patient name', 'visible_if' => ['field' => 'patient', 'op' => 'neq', 'value' => 'self'], 'required_if' => ['field' => 'patient', 'op' => 'neq', 'value' => 'self']],
            ['key' => 'hospital', 'type' => 'text', 'label' => 'Hospital / doctor', 'required' => true],
            ['key' => 'treatment', 'type' => 'select', 'label' => 'Treatment', 'required' => true, 'options' => [['value' => 'opd', 'label' => 'Outpatient'], ['value' => 'ipd', 'label' => 'Inpatient']]],
            ['key' => 'bills', 'type' => 'table', 'label' => 'Bills', 'required' => true, 'max_rows' => 40, 'columns' => [
                ['key' => 'date', 'type' => 'date', 'label' => 'Date', 'required' => true],
                ['key' => 'details', 'type' => 'text', 'label' => 'Bill no. / details', 'required' => true],
                ['key' => 'amount', 'type' => 'decimal', 'label' => 'Amount (₹)', 'required' => true],
            ]],
            ['key' => 'total', 'type' => 'calculated', 'label' => 'Total claimed (₹)', 'formula' => 'sum(bills.amount)'],
            ['key' => 'prescriptions', 'type' => 'files', 'label' => 'Prescriptions and bills', 'required' => true, 'accept' => ['pdf', 'jpg', 'jpeg', 'png'], 'max_files' => 20],
            ...$this->claimFinanceFields(),
        ], $this->claimSteps('medical'), $this->claimEdges(), 'Medical claim ({{ patient }})', $this->staff() + ['validator' => 'claim.medical']);
    }

    private function certificates(): void
    {
        foreach ([
            ['noc-passport', 'NOC for passport', 'NOC-PASSPORT', [['key' => 'passport_purpose', 'type' => 'select', 'label' => 'For', 'required' => true, 'options' => [['value' => 'a fresh passport', 'label' => 'A fresh passport'], ['value' => 'renewal of passport', 'label' => 'Renewal']]], ['key' => 'travel_plans', 'type' => 'textarea', 'label' => 'Any travel abroad planned?']]],
            ['noc-higher-studies', 'NOC for higher studies', 'NOC-HS', [['key' => 'course', 'type' => 'text', 'label' => 'Course', 'required' => true], ['key' => 'institution', 'type' => 'text', 'label' => 'Institution', 'required' => true], ['key' => 'study_mode', 'type' => 'select', 'label' => 'Mode', 'required' => true, 'options' => [['value' => 'part-time', 'label' => 'Part-time'], ['value' => 'full-time with leave', 'label' => 'Full-time (with leave)']]]]],
            ['noc-outside-employment', 'NOC for outside employment', 'NOC-OE', [['key' => 'organisation', 'type' => 'text', 'label' => 'Organisation', 'required' => true], ['key' => 'post', 'type' => 'text', 'label' => 'Post applied for', 'required' => true], ['key' => 'advertisement', 'type' => 'file', 'label' => 'Advertisement', 'accept' => ['pdf', 'jpg', 'png']]]],
            ['experience-certificate', 'Experience certificate', 'EXP', [['key' => 'service_period', 'type' => 'text', 'label' => 'Period to be certified', 'required' => true, 'placeholder' => 'since 1 July 2018'], ['key' => 'purpose', 'type' => 'text', 'label' => 'Purpose', 'required' => true]]],
        ] as [$key, $name, $template, $fields]) {
            $this->define($key, $name, "Request a {$name}; it is issued as a numbered PDF with a verification code.".self::NOTE, 'certificates', $fields, [
                $this->approval('hod', 'HoD recommendation', self::HOD, ['labels' => ['approve' => 'Recommend']]),
                $this->approval('registrar', 'Registrar', ['type' => 'authority', 'authority' => 'REGISTRAR'], ['labels' => ['approve' => 'Approve and sign']]),
                $this->auto('issue', 'Issue certificate', 'registrar.issue_certificate', ['template' => $template]),
                ...$this->ends(),
            ], [['start', 'hod'], ['hod', 'registrar', 'approve'], ['hod', 'refused', 'reject'], ['registrar', 'issue', 'approve'], ['registrar', 'refused', 'reject'], ['issue', 'done', 'success']],
                "{$name} for {{ initiator.name }}", $this->staff());
        }
    }

    private function circularApproval(): void
    {
        $this->define('circular-approval', 'Circular / office order approval', 'Approval and publication of circulars, office orders and notices.'.self::NOTE, 'circulars', [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
            ['key' => 'kind', 'type' => 'text', 'label' => 'Kind', 'required' => true],
            ['key' => 'summary', 'type' => 'textarea', 'label' => 'Summary', 'required' => true],
        ], [
            $this->approval('registrar', 'Registrar', ['type' => 'authority', 'authority' => 'REGISTRAR'], ['labels' => ['approve' => 'Approve for issue']]),
            $this->auto('publish', 'Publish', 'registrar.publish_circular'),
            ...$this->ends(),
        ], [['start', 'registrar'], ['registrar', 'publish', 'approve'], ['registrar', 'refused', 'reject'], ['publish', 'done', 'success']],
            '{{ kind }}: {{ title }}', ['initiators' => ['roles' => ['registrar_staff', 'system_admin']], 'fallback' => ['type' => 'authority', 'authority' => 'REGISTRAR']]);
    }

    private function rti(): void
    {
        $this->define('rti-request', 'RTI application', 'Register an RTI application, assign the PIO and track the statutory reply date.'.self::NOTE, 'rti', [
            ['key' => 'applicant_name', 'type' => 'text', 'label' => 'Applicant', 'required' => true],
            ['key' => 'applicant_address', 'type' => 'textarea', 'label' => 'Address', 'required' => true],
            ['key' => 'applicant_email', 'type' => 'email', 'label' => 'E-mail'],
            ['key' => 'applicant_phone', 'type' => 'tel', 'label' => 'Phone'],
            ['key' => 'received_on', 'type' => 'date', 'label' => 'Received on', 'required' => true, 'max' => 'today'],
            ['key' => 'received_via', 'type' => 'select', 'label' => 'Received via', 'required' => true, 'options' => array_map(fn ($v) => ['value' => $v, 'label' => strtoupper($v) === 'APIO' ? 'APIO (transferred)' : ucfirst($v)], ['post', 'email', 'portal', 'hand', 'apio'])],
            ['key' => 'life_liberty', 'type' => 'checkbox', 'label' => 'Urgency', 'placeholder' => 'Concerns the life or liberty of a person'],
            ['key' => 'fee_paid', 'type' => 'checkbox', 'label' => 'Fee', 'placeholder' => 'Application fee received'],
            ['key' => 'is_bpl', 'type' => 'checkbox', 'label' => 'BPL', 'placeholder' => 'Applicant is below poverty line (fee exempt)'],
            ['key' => 'subject', 'type' => 'text', 'label' => 'Subject', 'required' => true],
            ['key' => 'information_sought', 'type' => 'textarea', 'label' => 'Information sought', 'required' => true],
            ['key' => 'application', 'type' => 'file', 'label' => 'Scanned application', 'accept' => ['pdf', 'jpg', 'png']],
            ['key' => 'pio', 'type' => 'user', 'label' => 'Assign to PIO', 'required' => true, 'category' => 'staff'],
            ['key' => 'outcome', 'type' => 'select', 'label' => 'Outcome', 'editable_at' => ['pio'], 'required' => true, 'options' => [['value' => 'provided', 'label' => 'Information provided'], ['value' => 'partly', 'label' => 'Partly provided'], ['value' => 'denied', 'label' => 'Denied'], ['value' => 'transferred', 'label' => 'Transferred u/s 6(3)']]],
            ['key' => 'reply_summary', 'type' => 'remark', 'label' => 'Reply', 'editable_at' => ['pio']],
            ['key' => 'denial_sections', 'type' => 'text', 'label' => 'Exemption sections cited', 'editable_at' => ['pio'], 'visible_if' => ['field' => 'outcome', 'op' => 'in', 'value' => ['partly', 'denied']]],
            ['key' => 'transferred_to', 'type' => 'text', 'label' => 'Transferred to (public authority)', 'editable_at' => ['pio'], 'visible_if' => ['field' => 'outcome', 'op' => 'eq', 'value' => 'transferred']],
            ['key' => 'reply_document', 'type' => 'file', 'label' => 'Signed reply', 'editable_at' => ['pio'], 'accept' => ['pdf']],
            ['key' => 'dispatched_on', 'type' => 'date', 'label' => 'Dispatched on', 'editable_at' => ['dispatch']],
            ['key' => 'dispatch_mode', 'type' => 'select', 'label' => 'Dispatch mode', 'editable_at' => ['dispatch'], 'options' => array_map(fn ($v) => ['value' => $v, 'label' => ucfirst($v)], ['post', 'email', 'portal', 'hand'])],
        ], [
            $this->auto('register', 'Enter in RTI register', 'registrar.rti', ['step' => 'register']),
            ['pio', 'review', 'PIO reply', ['assignees' => [['type' => 'field', 'field' => 'pio']], 'actions' => ['approve', 'forward', 'return'], 'labels' => ['approve' => 'Reply ready'], 'instructions' => 'Prepare the reply within the statutory date shown. Forward to a colleague holding the information if needed.', 'sla' => ['due_field' => 'due_on', 'escalations' => [['after' => 1, 'unit' => 'days', 'to' => ['type' => 'authority', 'authority' => 'RTI_FAA'], 'action' => 'notify']]]]],
            // Clerical, not a decision: the clerk who registered it may dispatch it.
            ['dispatch', 'verification', 'RTI cell: dispatch', ['assignees' => [['type' => 'role', 'role' => 'rti_cell']], 'actions' => ['approve', 'return'], 'labels' => ['approve' => 'Dispatched'], 'allow_self' => true, 'sla' => ['due_field' => 'due_on']]],
            $this->auto('reply', 'Record reply in register', 'registrar.rti', ['step' => 'reply']),
            ['done', 'end', 'Disposed', ['outcome' => 'approved']],
        ], [['start', 'register'], ['register', 'pio', 'success'], ['pio', 'dispatch', 'approve'], ['dispatch', 'reply', 'approve'], ['reply', 'done', 'success']],
            'RTI: {{ subject }} ({{ applicant_name }})', ['initiators' => ['roles' => ['rti_cell', 'system_admin']], 'validator' => 'rti.request', 'fallback' => ['type' => 'role', 'role' => 'rti_cell']]);
    }

    private function rtiAppeal(): void
    {
        $this->define('rti-appeal', 'RTI first appeal', 'Register a first appeal and track the appellate authority\'s decision date.'.self::NOTE, 'rti', [
            ['key' => 'rti', 'type' => 'select', 'label' => 'RTI application', 'required' => true, 'source' => 'rti_requests'],
            ['key' => 'appeal_received_on', 'type' => 'date', 'label' => 'Appeal received on', 'required' => true, 'max' => 'today'],
            ['key' => 'grounds', 'type' => 'textarea', 'label' => 'Grounds of appeal', 'required' => true],
            ['key' => 'appeal_document', 'type' => 'file', 'label' => 'Appeal', 'accept' => ['pdf', 'jpg', 'png']],
            ['key' => 'decision', 'type' => 'select', 'label' => 'Decision', 'editable_at' => ['faa'], 'options' => [['value' => 'allowed', 'label' => 'Allowed'], ['value' => 'partly', 'label' => 'Partly allowed'], ['value' => 'dismissed', 'label' => 'Dismissed']]],
            ['key' => 'order', 'type' => 'remark', 'label' => 'Order', 'editable_at' => ['faa']],
        ], [
            $this->auto('filed', 'Record appeal', 'registrar.rti', ['step' => 'appeal_filed']),
            ['faa', 'approval', 'First Appellate Authority', ['assignees' => [['type' => 'authority', 'authority' => 'RTI_FAA']], 'actions' => ['approve'], 'labels' => ['approve' => 'Order issued'], 'sla' => ['due_field' => 'appeal_due_on']]],
            $this->auto('decided', 'Record decision', 'registrar.rti', ['step' => 'appeal_decided']),
            ['done', 'end', 'Decided', ['outcome' => 'approved']],
        ], [['start', 'filed'], ['filed', 'faa', 'success'], ['faa', 'decided', 'approve'], ['decided', 'done', 'success']],
            'RTI appeal: {{ rti_number }}', ['initiators' => ['roles' => ['rti_cell', 'system_admin']], 'validator' => 'rti.appeal', 'fallback' => ['type' => 'role', 'role' => 'rti_cell']]);
    }
}
