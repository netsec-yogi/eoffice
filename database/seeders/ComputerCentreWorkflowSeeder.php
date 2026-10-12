<?php

namespace Database\Seeders;

use App\Modules\Identity\Models\Authority;
use App\Modules\Identity\Models\User;
use Database\Seeders\Concerns\DefinesWorkflows;
use Illuminate\Database\Seeder;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Computer Centre pilot (Phase 3): forms and workflows, created through the
 * same services the designer uses -- they are configuration, not code, and
 * the Computer Centre can change any of them in Admin -> Workflows.
 *
 * The routes are EXAMPLES drawn up from common practice; each description
 * says so. Approval levels, deadlines and quotas must be confirmed by the
 * Head, Computer Centre before production use. Idempotent: existing
 * workflows are left alone.
 */
class ComputerCentreWorkflowSeeder extends Seeder
{
    use DefinesWorkflows;

    private const NOTE = ' (Example route: confirm steps and deadlines with the Computer Centre.)';

    public function run(): void
    {
        $this->actor = User::role('system_admin')->first() ?? throw new RuntimeException('Seed a system administrator first (AdminSeeder).');

        // Offices the routes refer to. Holders are assigned by administrators.
        foreach ([['HOD', 'Head of Department', 'department'], ['CC_HEAD', 'Head, Computer Centre', 'institute'], ['REGISTRAR', 'Registrar', 'institute'], ['FINANCE_OFFICER', 'Finance Officer', 'institute']] as [$code, $name, $scope]) {
            Authority::query()->firstOrCreate(['code' => $code], ['name' => $name, 'scope' => $scope]);
        }
        Role::findOrCreate('cc_operator', 'web');

        $this->email();
        $this->wifi();
        $this->vpn();
        $this->vm();
        $this->equipment();
        $this->software();
        $this->deprovision();
        $this->noDues();
    }

    // ------------------------------------------------------------------

    private function email(): void
    {
        $this->define('it-email', 'Institute e-mail account', 'An official e-mail account for new faculty, staff and students.'.self::NOTE, 'it_services', [
            ['key' => 'purpose', 'type' => 'textarea', 'label' => 'Why do you need the account?', 'required' => true],
            ['key' => 'preferred_address', 'type' => 'text', 'label' => 'Preferred address (before @)', 'help' => 'Leave empty to use your username. The Computer Centre may change it if taken.', 'pattern' => '^[a-z0-9._-]{2,40}$'],
            ['key' => 'alternate_email', 'type' => 'email', 'label' => 'Current personal e-mail', 'required' => true],
            ['key' => 'aup', 'type' => 'checkbox', 'label' => 'Acceptable use', 'placeholder' => 'I accept the institute IT acceptable-use policy', 'required' => true],
        ], [
            $this->recommend(),
            $this->ccVerify(),
            ['provision', 'automation', 'Create mailbox', ['action' => 'cc.provision_email', 'on_blocked' => ['type' => 'role', 'role' => 'cc_operator']]],
            ['record', 'automation', 'Record in ledger', ['action' => 'cc.record_account', 'settings' => ['kind' => 'email']]],
            ['done', 'end', 'Account created', ['outcome' => 'approved']],
            ['refused', 'end', 'Not approved', ['outcome' => 'rejected']],
        ], [
            ['start', 'recommend'], ['recommend', 'cc_verify', 'approve'], ['recommend', 'refused', 'reject'],
            ['cc_verify', 'provision', 'approve'], ['cc_verify', 'refused', 'reject'],
            ['provision', 'record', 'success'], ['record', 'done', 'success'],
        ], 'E-mail account for {{ initiator.name }}', ['fallback' => ['type' => 'authority', 'authority' => 'HOD', 'department' => 'initiator']]);
    }

    private function wifi(): void
    {
        $this->define('it-wifi', 'Wi-Fi access', 'Campus Wi-Fi for your devices.'.self::NOTE, 'it_services', [
            ['key' => 'devices', 'type' => 'table', 'label' => 'Devices', 'required' => true, 'max_rows' => 5, 'columns' => [
                ['key' => 'device', 'type' => 'select', 'label' => 'Device', 'required' => true, 'options' => [['value' => 'laptop', 'label' => 'Laptop'], ['value' => 'phone', 'label' => 'Phone'], ['value' => 'tablet', 'label' => 'Tablet']]],
                ['key' => 'mac', 'type' => 'text', 'label' => 'MAC address (optional)'],
            ]],
            ['key' => 'valid_until', 'type' => 'date', 'label' => 'Needed until', 'required' => true, 'min' => 'today'],
            ['key' => 'aup', 'type' => 'checkbox', 'label' => 'Acceptable use', 'placeholder' => 'I accept the institute IT acceptable-use policy', 'required' => true],
        ], [
            $this->ccVerify(),
            ['grant', 'automation', 'Grant Wi-Fi access', ['action' => 'cc.grant_network_access', 'settings' => ['profile' => 'wifi'], 'on_blocked' => ['type' => 'role', 'role' => 'cc_operator']]],
            ['record', 'automation', 'Record in ledger', ['action' => 'cc.record_account', 'settings' => ['kind' => 'wifi']]],
            ['done', 'end', 'Access granted', ['outcome' => 'approved']],
            ['refused', 'end', 'Not approved', ['outcome' => 'rejected']],
        ], [
            ['start', 'cc_verify'], ['cc_verify', 'grant', 'approve'], ['cc_verify', 'refused', 'reject'],
            ['grant', 'record', 'success'], ['record', 'done', 'success'],
        ], 'Wi-Fi access for {{ initiator.name }}', ['fallback' => ['type' => 'authority', 'authority' => 'HOD', 'department' => 'initiator']]);
    }

    private function vpn(): void
    {
        $this->define('it-vpn', 'VPN access', 'Remote access to institute systems from outside the campus.'.self::NOTE, 'it_services', [
            ['key' => 'justification', 'type' => 'textarea', 'label' => 'Justification', 'required' => true],
            ['key' => 'resources', 'type' => 'textarea', 'label' => 'Systems you need to reach', 'required' => true, 'help' => 'Servers, applications or networks.'],
            ['key' => 'valid_until', 'type' => 'date', 'label' => 'Needed until', 'required' => true, 'min' => 'today'],
            ['key' => 'aup', 'type' => 'checkbox', 'label' => 'Acceptable use', 'placeholder' => 'I will not share my VPN credentials', 'required' => true],
        ], [
            $this->recommend(),
            ['cc_head', 'approval', 'Head, Computer Centre', ['assignees' => [['type' => 'authority', 'authority' => 'CC_HEAD']], 'actions' => ['approve', 'reject', 'return'], 'sla' => ['duration' => 2, 'unit' => 'working_days']]],
            ['grant', 'automation', 'Grant VPN access', ['action' => 'cc.grant_network_access', 'settings' => ['profile' => 'vpn'], 'on_blocked' => ['type' => 'role', 'role' => 'cc_operator']]],
            ['record', 'automation', 'Record in ledger', ['action' => 'cc.record_account', 'settings' => ['kind' => 'vpn']]],
            ['done', 'end', 'Access granted', ['outcome' => 'approved']],
            ['refused', 'end', 'Not approved', ['outcome' => 'rejected']],
        ], [
            ['start', 'recommend'], ['recommend', 'cc_head', 'approve'], ['recommend', 'refused', 'reject'],
            ['cc_head', 'grant', 'approve'], ['cc_head', 'refused', 'reject'],
            ['grant', 'record', 'success'], ['record', 'done', 'success'],
        ], 'VPN access for {{ initiator.name }}', ['initiators' => ['categories' => ['faculty', 'staff']], 'fallback' => ['type' => 'authority', 'authority' => 'HOD', 'department' => 'initiator']]);
    }

    private function vm(): void
    {
        $this->define('it-vm', 'Server / virtual machine', 'A virtual machine on the institute cluster for teaching, research or office use.'.self::NOTE, 'it_services', [
            ['key' => 'purpose', 'type' => 'textarea', 'label' => 'Purpose', 'required' => true],
            ['key' => 'project', 'type' => 'text', 'label' => 'Project / course'],
            ['key' => 'os', 'type' => 'select', 'label' => 'Operating system', 'required' => true, 'options' => [['value' => 'ubuntu-24.04', 'label' => 'Ubuntu 24.04 LTS'], ['value' => 'debian-12', 'label' => 'Debian 12'], ['value' => 'other', 'label' => 'Other (explain in purpose)']]],
            ['key' => 'cpu', 'type' => 'number', 'label' => 'vCPUs', 'required' => true, 'min' => 1, 'max' => 64],
            ['key' => 'ram_gb', 'type' => 'number', 'label' => 'Memory (GB)', 'required' => true, 'min' => 1, 'max' => 512],
            ['key' => 'disk_gb', 'type' => 'number', 'label' => 'Disk (GB)', 'required' => true, 'min' => 10, 'max' => 4096],
            ['key' => 'network', 'type' => 'radio', 'label' => 'Network', 'required' => true, 'options' => [['value' => 'internal', 'label' => 'Campus only'], ['value' => 'public', 'label' => 'Public IP (needs Head, CC approval)']]],
            ['key' => 'ports', 'type' => 'text', 'label' => 'Ports to open', 'visible_if' => ['field' => 'network', 'op' => 'eq', 'value' => 'public'], 'required_if' => ['field' => 'network', 'op' => 'eq', 'value' => 'public']],
            ['key' => 'valid_until', 'type' => 'date', 'label' => 'Needed until', 'required' => true, 'min' => 'today'],
        ], [
            ['quota', 'automation', 'Check quota', ['action' => 'cc.check_vm_quota']],
            ['quota_exception', 'approval', 'Approve above-quota request', ['assignees' => [['type' => 'authority', 'authority' => 'CC_HEAD']], 'actions' => ['approve', 'reject'], 'instructions' => 'The request exceeds the standard quota for the requester\'s category. Approve only with a reason.']],
            $this->recommend(),
            ['network_route', 'decision', 'Public IP?'],
            ['cc_head', 'approval', 'Head, Computer Centre (public IP)', ['assignees' => [['type' => 'authority', 'authority' => 'CC_HEAD']], 'actions' => ['approve', 'reject', 'return']]],
            ['provision', 'automation', 'Create VM', ['action' => 'cc.provision_vm', 'on_blocked' => ['type' => 'role', 'role' => 'cc_operator']]],
            ['record', 'automation', 'Record in ledger', ['action' => 'cc.record_account', 'settings' => ['kind' => 'vm']]],
            ['done', 'end', 'VM ready', ['outcome' => 'approved']],
            ['refused', 'end', 'Not approved', ['outcome' => 'rejected']],
        ], [
            ['start', 'quota'], ['quota', 'recommend', 'success'], ['quota', 'quota_exception', 'failure'],
            ['quota_exception', 'recommend', 'approve'], ['quota_exception', 'refused', 'reject'],
            ['recommend', 'network_route', 'approve'], ['recommend', 'refused', 'reject'],
            ['network_route', 'cc_head', 'default', ['field' => 'network', 'op' => 'eq', 'value' => 'public'], 10],
            ['network_route', 'provision', 'default', null, 20],
            ['cc_head', 'provision', 'approve'], ['cc_head', 'refused', 'reject'],
            ['provision', 'record', 'success'], ['record', 'done', 'success'],
        ], 'VM: {{ project }} ({{ cpu }} vCPU / {{ ram_gb }} GB)', ['initiators' => ['categories' => ['faculty', 'staff', 'student']], 'fallback' => ['type' => 'authority', 'authority' => 'HOD', 'department' => 'initiator']]);
    }

    private function equipment(): void
    {
        $this->define('it-asset', 'IT equipment issue', 'Borrow a laptop, monitor, printer or other IT equipment from the Computer Centre store.'.self::NOTE, 'it_services', [
            ['key' => 'category', 'type' => 'select', 'label' => 'What do you need?', 'required' => true, 'options' => array_map(fn ($c) => ['value' => $c, 'label' => ucfirst($c)], ['laptop', 'desktop', 'monitor', 'printer', 'projector', 'peripheral', 'other'])],
            ['key' => 'justification', 'type' => 'textarea', 'label' => 'Justification', 'required' => true],
            ['key' => 'needed_by', 'type' => 'date', 'label' => 'Needed by', 'min' => 'today'],
            ['key' => 'asset_number', 'type' => 'text', 'label' => 'Asset to issue', 'help' => 'Filled in by the Computer Centre store.', 'editable_at' => ['store'], 'required_if' => ['field' => 'category', 'op' => 'not_empty']],
        ], [
            $this->recommend(),
            ['store', 'verification', 'Computer Centre store', ['assignees' => [['type' => 'role', 'role' => 'cc_operator']], 'actions' => ['approve', 'reject', 'return'], 'labels' => ['approve' => 'Issue this asset'], 'instructions' => 'Choose an asset in stock and enter its number.', 'sla' => ['duration' => 2, 'unit' => 'working_days']]],
            ['issue', 'automation', 'Record issue', ['action' => 'cc.issue_asset']],
            ['done', 'end', 'Issued', ['outcome' => 'approved']],
            ['refused', 'end', 'Not approved', ['outcome' => 'rejected']],
        ], [
            ['start', 'recommend'], ['recommend', 'store', 'approve'], ['recommend', 'refused', 'reject'],
            ['store', 'issue', 'approve'], ['store', 'refused', 'reject'],
            ['issue', 'done', 'success'], ['issue', 'store', 'failure'],
        ], '{{ category }} for {{ initiator.name }}', ['initiators' => ['categories' => ['faculty', 'staff']], 'fallback' => ['type' => 'authority', 'authority' => 'HOD', 'department' => 'initiator']]);
    }

    private function software(): void
    {
        $this->define('it-software', 'Software licence', 'A seat of licensed software from the institute licence pool.'.self::NOTE, 'it_services', [
            ['key' => 'software', 'type' => 'select', 'label' => 'Software', 'required' => true, 'source' => 'software_titles'],
            ['key' => 'purpose', 'type' => 'textarea', 'label' => 'Purpose', 'required' => true],
        ], [
            $this->recommend(),
            ['allocate', 'automation', 'Allocate seat', ['action' => 'cc.allocate_licence']],
            ['cc_waitlist', 'verification', 'No seat free: Computer Centre', ['assignees' => [['type' => 'role', 'role' => 'cc_operator']], 'actions' => ['approve', 'reject'], 'labels' => ['approve' => 'Seat allocated by hand'], 'instructions' => 'All seats are in use. Free or buy a seat and allocate it in Software licences, or reject.']],
            ['done', 'end', 'Allocated', ['outcome' => 'approved']],
            ['refused', 'end', 'Not approved', ['outcome' => 'rejected']],
        ], [
            ['start', 'recommend'], ['recommend', 'allocate', 'approve'], ['recommend', 'refused', 'reject'],
            ['allocate', 'done', 'success'], ['allocate', 'cc_waitlist', 'failure'],
            ['cc_waitlist', 'done', 'approve'], ['cc_waitlist', 'refused', 'reject'],
        ], 'Software licence for {{ initiator.name }}', ['initiators' => ['categories' => ['faculty', 'staff', 'student']], 'fallback' => ['type' => 'authority', 'authority' => 'HOD', 'department' => 'initiator']]);
    }

    private function deprovision(): void
    {
        $this->define('it-deprovision', 'IT account deprovisioning', 'Review and close the IT access of someone leaving the institute. Nothing is deleted: access is revoked, mailboxes are suspended and retained as decided at review.'.self::NOTE, 'it_services', [
            ['key' => 'account_holder', 'type' => 'user', 'label' => 'Person leaving', 'required' => true],
            ['key' => 'reason', 'type' => 'select', 'label' => 'Reason', 'required' => true, 'options' => array_map(fn ($r) => ['value' => $r, 'label' => ucfirst(str_replace('_', ' ', $r))], ['no_dues', 'superannuation', 'resignation', 'transfer', 'course_completed', 'other'])],
            ['key' => 'last_working_day', 'type' => 'date', 'label' => 'Last working day', 'required' => true],
            ['key' => 'notes', 'type' => 'textarea', 'label' => 'Notes'],
            ['key' => 'legal_hold', 'type' => 'checkbox', 'label' => 'Legal hold', 'placeholder' => 'A legal hold or inquiry applies: do not change any account', 'editable_at' => ['review']],
            ['key' => 'mailbox_retention_days', 'type' => 'number', 'label' => 'Keep the suspended mailbox for (days)', 'min' => 0, 'max' => 3650, 'editable_at' => ['review'], 'help' => 'Per the institute retention policy.'],
            ['key' => 'data_transfer_to', 'type' => 'user', 'label' => 'Transfer shared data and project resources to', 'editable_at' => ['review']],
            ['key' => 'review_notes', 'type' => 'remark', 'label' => 'Review notes (research data, exceptions, reactivation)', 'editable_at' => ['review']],
        ], [
            ['review', 'verification', 'Computer Centre review', ['assignees' => [['type' => 'role', 'role' => 'cc_operator']], 'actions' => ['approve', 'reject', 'return'], 'labels' => ['approve' => 'Review complete'], 'instructions' => 'Check employment/enrolment status, data ownership, projects and legal holds. Complete the review fields.', 'sla' => ['duration' => 3, 'unit' => 'working_days']]],
            ['hold_check', 'decision', 'Legal hold?'],
            ['cc_head', 'approval', 'Head, Computer Centre', ['assignees' => [['type' => 'authority', 'authority' => 'CC_HEAD']], 'actions' => ['approve', 'reject', 'return']]],
            ['apply', 'automation', 'Apply deprovisioning', ['action' => 'cc.deprovision', 'on_blocked' => ['type' => 'role', 'role' => 'cc_operator']]],
            ['done', 'end', 'Access closed', ['outcome' => 'approved']],
            ['held', 'end', 'Not changed (legal hold)', ['outcome' => 'rejected']],
            ['refused', 'end', 'Not approved', ['outcome' => 'rejected']],
        ], [
            ['start', 'review'], ['review', 'hold_check', 'approve'], ['review', 'refused', 'reject'],
            ['hold_check', 'held', 'default', ['field' => 'legal_hold', 'op' => 'eq', 'value' => true], 10],
            ['hold_check', 'cc_head', 'default', null, 20],
            ['cc_head', 'apply', 'approve'], ['cc_head', 'refused', 'reject'],
            ['apply', 'done', 'success'], ['apply', 'held', 'failure'],
        ], 'Deprovisioning ({{ reason }})', ['initiators' => ['roles' => ['cc_operator', 'system_admin']], 'fallback' => ['type' => 'authority', 'authority' => 'HOD', 'department' => 'initiator']]);
    }

    private function noDues(): void
    {
        $this->define('no-dues', 'No-dues clearance', 'Clearance from departments before leaving. On approval, a separate review of your IT accounts is opened; nothing is closed automatically.'.self::NOTE, 'it_services', [
            ['key' => 'last_working_day', 'type' => 'date', 'label' => 'Last working day', 'required' => true],
            ['key' => 'reason', 'type' => 'select', 'label' => 'Reason', 'required' => true, 'options' => array_map(fn ($r) => ['value' => $r, 'label' => ucfirst(str_replace('_', ' ', $r))], ['superannuation', 'resignation', 'transfer', 'course_completed', 'other'])],
            ['key' => 'forwarding_address', 'type' => 'textarea', 'label' => 'Forwarding address'],
        ], [
            ['split', 'parallel_split', 'Clearances'],
            ['cc_check', 'automation', 'IT assets check', ['action' => 'cc.check_clearance']],
            ['cc_clear', 'verification', 'Computer Centre clearance', ['assignees' => [['type' => 'role', 'role' => 'cc_operator']], 'actions' => ['approve', 'reject'], 'instructions' => 'IT assets are still issued (see history). Clear once returned.']],
            ['hod_clear', 'approval', 'Department clearance', ['assignees' => [['type' => 'authority', 'authority' => 'HOD', 'department' => 'initiator']], 'actions' => ['approve', 'reject', 'return']]],
            ['accounts_clear', 'approval', 'Accounts clearance', ['assignees' => [['type' => 'authority', 'authority' => 'FINANCE_OFFICER']], 'actions' => ['approve', 'reject', 'return']]],
            ['join', 'parallel_join', 'All cleared', ['wait' => 'all']],
            ['registrar', 'approval', 'Registrar', ['assignees' => [['type' => 'authority', 'authority' => 'REGISTRAR']], 'actions' => ['approve', 'reject', 'return']]],
            ['open_review', 'automation', 'Open IT account review', ['action' => 'core.start_workflow', 'settings' => ['workflow' => 'it-deprovision', 'copy' => ['account_holder' => 'initiator', 'reason' => 'no_dues', 'last_working_day' => 'last_working_day']]]],
            ['done', 'end', 'No dues', ['outcome' => 'approved']],
            ['refused', 'end', 'Dues outstanding', ['outcome' => 'rejected']],
        ], [
            ['start', 'split'], ['split', 'cc_check'], ['split', 'hod_clear'], ['split', 'accounts_clear'],
            ['cc_check', 'join', 'success'], ['cc_check', 'cc_clear', 'failure'],
            ['cc_clear', 'join', 'approve'], ['cc_clear', 'refused', 'reject'],
            ['hod_clear', 'join', 'approve'], ['hod_clear', 'refused', 'reject'],
            ['accounts_clear', 'join', 'approve'], ['accounts_clear', 'refused', 'reject'],
            ['join', 'registrar'], ['registrar', 'open_review', 'approve'], ['registrar', 'refused', 'reject'],
            ['open_review', 'done', 'success'],
        ], 'No-dues: {{ initiator.name }}', ['initiators' => ['categories' => ['faculty', 'staff', 'student']], 'fallback' => ['type' => 'authority', 'authority' => 'HOD', 'department' => 'initiator']]);
    }

    // ------------------------------------------------------------------

    private function recommend(): array
    {
        // Reporting officer, else (e.g. students) the HoD via the fallback.
        return ['recommend', 'approval', 'Recommendation', [
            'assignees' => [['type' => 'reporting_manager', 'levels' => 1]],
            'actions' => ['approve', 'reject', 'return'],
            'labels' => ['approve' => 'Recommend'],
            'sla' => ['duration' => 2, 'unit' => 'working_days', 'escalations' => [['after' => 1, 'unit' => 'working_days', 'to' => ['type' => 'authority', 'authority' => 'HOD', 'department' => 'initiator'], 'action' => 'notify']]],
        ]];
    }

    private function ccVerify(): array
    {
        return ['cc_verify', 'verification', 'Computer Centre verification', [
            'assignees' => [['type' => 'role', 'role' => 'cc_operator']],
            'actions' => ['approve', 'reject', 'return'],
            'labels' => ['approve' => 'Verified'],
            'sla' => ['duration' => 1, 'unit' => 'working_days', 'escalations' => [['after' => 1, 'unit' => 'working_days', 'to' => ['type' => 'authority', 'authority' => 'CC_HEAD'], 'action' => 'notify']]],
        ]];
    }
}
