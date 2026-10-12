<?php

namespace Database\Seeders;

use App\Modules\Identity\Models\User;
use App\Modules\OfficialDocuments\Models\PdfTemplate;
use App\Modules\OfficialDocuments\Services\TemplateService;
use App\Modules\Workflow\Models\WorkflowDefinition;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * The institute's own forms (resources/official-forms, as published on
 * iiitm.ac.in). Forms that match an existing workflow are mapped, published
 * and attached; the rest are registered unpublished so the office can map
 * them once their workflow exists. Box positions were measured from each
 * file (text positions and ruled lines); the office should still check a
 * sample of every form before relying on it (A-25).
 *
 * Coordinates: PDF points, top-left origin.
 */
class InstituteFormsSeeder extends Seeder
{
    private const DIR = 'resources/official-forms/';

    public function run(): void
    {
        $actor = User::role('system_admin')->first() ?? throw new RuntimeException('Seed a system administrator first.');
        $service = app(TemplateService::class);

        foreach ($this->forms() as $form) {
            if (PdfTemplate::query()->where('key', $form['key'])->exists()) {
                $this->command?->line("  {$form['key']}: exists, left unchanged");

                continue;
            }
            $path = base_path(self::DIR.$form['file']);
            $template = $service->create(['key' => $form['key'], 'name' => $form['name'], 'description' => $form['description']],
                new UploadedFile($path, $form['file'], 'application/pdf', null, true), $actor);
            $version = $template->versions()->sole();
            $workflow = isset($form['workflow']) ? WorkflowDefinition::query()->where('key', $form['workflow'])->first() : null;

            if (! isset($form['mapping']) || ! $workflow) {
                $this->command?->line("  {$form['key']}: registered, not mapped".(isset($form['workflow']) && ! $workflow ? " (workflow {$form['workflow']} missing)" : ''));

                continue;
            }
            $service->saveMapping($version, $form['mapping'], $actor);
            $service->publish($version->fresh(), $actor);
            $service->saveOutput($template, ['workflow_definition_id' => $workflow->id] + $form['output'], $actor);
            $this->command?->line("  {$form['key']}: mapped, published, issued by {$workflow->key}");
        }
    }

    /** One mapped box. */
    private function f(string $key, string $label, int $page, string $source, array $box, array $extra = []): array
    {
        // $extra leads: with `+` the left-hand keys win.
        return $extra + ['key' => $key, 'label' => $label, 'page' => $page, 'type' => 'text', 'source' => $source,
            'x' => $box[0], 'y' => $box[1], 'w' => $box[2], 'h' => $box[3],
            'font' => 'sans', 'font_size' => 10, 'min_font_size' => 6.5, 'align' => 'L', 'overflow' => 'shrink', 'format' => ['kind' => 'auto']];
    }

    /** Short blanks that may receive long names ("Computer Science and Engineering"): two small lines if needed. */
    private function narrow(): array
    {
        return ['type' => 'multiline', 'font_size' => 8.5, 'min_font_size' => 5.5, 'line_height' => 1.1];
    }

    private function date(array $extra = []): array
    {
        return ['format' => ['kind' => 'date']] + $extra;
    }

    private function amount(array $extra = []): array
    {
        return ['format' => ['kind' => 'amount']] + $extra;
    }

    /** A printed record of approval, placed beside (never on) the signature line. */
    private function statement(string $key, string $label, int $page, string $step, array $box, float $size = 7.5): array
    {
        return $this->f($key, $label, $page, "approval.{$step}.statement", $box, ['type' => 'multiline', 'font_size' => $size, 'min_font_size' => 5.5, 'line_height' => 1.15]);
    }

    private function forms(): array
    {
        $leaveGroups = ['download_groups' => ['applicant', 'records'], 'required_steps' => ['sanction']];
        $financeGroups = ['download_groups' => ['applicant', 'records'], 'required_steps' => ['verify', 'sanction']];

        return [
            [
                'key' => 'casual-leave', 'file' => 'Application for Casual Leave_Station Leave_Special Casual Leave.pdf',
                'name' => 'Application for Casual Leave / RH / Station Leave / Special Casual Leave',
                'description' => 'Issued for leave requests of kind CL, SL (station leave) and DL.',
                'workflow' => 'leave', 'output' => $leaveGroups + ['condition' => ['field' => 'leave_type', 'op' => 'in', 'value' => ['CL', 'SL', 'DL']]],
                'mapping' => [
                    $this->f('name', '1. Name', 1, 'applicant.name', [130, 199, 158, 17], ['required' => true]),
                    $this->f('file_no', '2. Personal File No.', 1, 'applicant.employee_id', [425, 199, 122, 17]),
                    $this->f('designation', '3. Designation', 1, 'applicant.designation', [161, 228, 120, 17]),
                    $this->f('department', '4. Department/Section', 1, 'applicant.department', [446, 225, 101, 21], $this->narrow()),
                    $this->f('leave_type', '5. Type of leave', 1, 'form_label.leave_type', [168, 257, 380, 17], ['required' => true]),
                    $this->f('from', '6. From', 1, 'form.from_date', [212, 287, 65, 17], $this->date(['required' => true])),
                    $this->f('to', '6. To', 1, 'form.to_date', [313, 287, 69, 17], $this->date(['required' => true])),
                    $this->f('days', '6. Total No. of Days', 1, 'form.days', [476, 287, 62, 17]),
                    $this->f('station_from', '7. Station leave from', 1, 'form.from_date', [228, 316, 56, 17], $this->date(['condition' => ['field' => 'form.leave_station', 'op' => 'eq', 'value' => true]])),
                    $this->f('station_to', '7. Station leave to', 1, 'form.to_date', [339, 316, 93, 17], $this->date(['condition' => ['field' => 'form.leave_station', 'op' => 'eq', 'value' => true]])),
                    $this->f('purpose', '8. Purpose', 1, 'form.reason', [153, 345, 383, 17], ['required' => true]),
                    $this->f('address', '9. Leave address', 1, 'form.address', [99, 397, 443, 44], ['type' => 'multiline', 'line_height' => 2.2]),
                    $this->f('responsibilities', '10. Responsibilities assigned', 1, 'form.arrangement', [99, 462, 442, 44], ['type' => 'multiline', 'line_height' => 2.2]),
                    $this->f('dated', 'Dated', 1, 'workflow.submitted_at', [112, 551, 72, 17], $this->date()),
                    $this->f('reference', 'Application number', 1, 'workflow.reference', [387, 572, 160, 14], ['font' => 'mono', 'font_size' => 8]),
                    $this->statement('hod_record', 'Head of Department / Reporting Officer (record)', 1, 'sanction', [300, 598, 250, 40]),
                    $this->statement('registrar_record', 'Registrar / Director (record)', 1, 'registrar', [190, 676, 360, 30]),
                ],
            ],
            [
                'key' => 'earned-leave', 'file' => 'Leave Application for Earned Leave_Half pay Leave_ Extra-ordinary Leave_Commuted Leave_Vacation Leave.pdf',
                'name' => 'Leave application for Earned / Half Pay / Extra-ordinary / Commuted / Vacation Leave',
                'description' => 'Issued for leave requests of kind EL and HPL. The bilingual form is filled on its English lines.',
                'workflow' => 'leave', 'output' => $leaveGroups + ['condition' => ['field' => 'leave_type', 'op' => 'in', 'value' => ['EL', 'HPL']]],
                'mapping' => [
                    $this->f('file_no', '1. Personal File No.', 1, 'applicant.employee_id', [146, 181, 108, 15], ['font_size' => 9.5]),
                    $this->f('name', '2. Name', 1, 'applicant.name', [368, 181, 176, 15], ['font_size' => 9.5, 'required' => true]),
                    $this->f('designation', '3. Designation', 1, 'applicant.designation', [127, 217, 104, 15], ['font_size' => 9.5]),
                    $this->f('department', '4. Department / Section', 1, 'applicant.department', [432, 214, 110, 19], $this->narrow()),
                    $this->f('leave_type', '5. Type of Leave', 1, 'form_label.leave_type', [134, 247, 108, 15], ['font_size' => 9.5, 'required' => true]),
                    $this->f('purpose', '6. Purpose', 1, 'form.reason', [378, 247, 165, 15], ['font_size' => 9.5, 'required' => true]),
                    $this->f('from', '7. from', 1, 'form.from_date', [282, 279, 92, 15], $this->date(['font_size' => 9.5, 'required' => true])),
                    $this->f('to', '7. to', 1, 'form.to_date', [388, 279, 62, 15], $this->date(['font_size' => 9.5, 'required' => true])),
                    $this->f('days', '7. No. of Days', 1, 'form.days', [505, 279, 32, 15], ['font_size' => 9.5]),
                    $this->f('classes', '8. Arrangement of Classes', 1, 'form.arrangement', [176, 326, 365, 15], ['font_size' => 9.5, 'min_font_size' => 6]),
                    $this->f('address', '10. Address during Leave', 1, 'form.address', [173, 423, 150, 15], ['font_size' => 9.5, 'min_font_size' => 6]),
                    $this->f('dated', 'Date', 1, 'workflow.submitted_at', [94, 472, 70, 15], $this->date(['font_size' => 9.5])),
                    $this->f('reference', 'Application number', 1, 'workflow.reference', [369, 446, 175, 12], ['font' => 'mono', 'font_size' => 8]),
                    $this->statement('hod_record', 'Recommendation of Head of Department (record)', 1, 'sanction', [60, 538, 300, 26]),
                    $this->statement('registrar_record', 'Registrar / Director (record)', 1, 'registrar', [60, 732, 270, 26]),
                ],
            ],
            [
                'key' => 'ta-advance', 'file' => 'Request for Approval of Journey and T. A. Advance Form (Within INDIA).pdf',
                'name' => 'Request for Approval of Journey and T. A. Advance (Within India)',
                'description' => 'Issued when a tour / travel authorisation is sanctioned.',
                'workflow' => 'tour', 'output' => ['download_groups' => ['applicant', 'records'], 'required_steps' => ['hod', 'sanction']],
                'mapping' => [
                    $this->f('name', 'Name', 1, 'applicant.name', [68, 116, 286, 14], ['required' => true]),
                    $this->f('pf_no', 'PF/Roll No.', 1, 'applicant.employee_id', [450, 116, 105, 14]),
                    $this->f('status_faculty', 'Status: Faculty', 1, 'applicant.category', [65.5, 151, 15.5, 9], ['type' => 'checkbox', 'checkbox' => ['match' => 'faculty', 'mark' => '✓']]),
                    $this->f('status_staff', 'Status: Staff', 1, 'applicant.category', [121.5, 151, 15.5, 9], ['type' => 'checkbox', 'checkbox' => ['match' => 'staff', 'mark' => '✓']]),
                    $this->f('designation', 'Designation', 1, 'applicant.designation', [92, 177, 76, 19], $this->narrow()),
                    $this->f('department', 'Department', 1, 'applicant.department', [234, 177, 79, 19], $this->narrow()),
                    $this->f('purpose', 'Purpose of Journey', 1, 'form.purpose', [351, 235, 205, 14], ['min_font_size' => 6, 'required' => true]),
                    $this->f('stay_from', 'Period of stay: From', 1, 'form.from_date', [152, 311, 119, 14], $this->date(['required' => true])),
                    $this->f('stay_to', 'Period of stay: to', 1, 'form.to_date', [309, 311, 118, 14], $this->date(['required' => true])),
                    $this->f('place', 'Place of Visit', 1, 'form.destination', [119, 332, 125, 14], ['required' => true]),
                    $this->f('commencement', 'Date of Commencement', 1, 'form.from_date', [369, 332, 179, 14], $this->date()),
                    $this->f('mode_road', 'Journey Mode: ROAD', 1, 'form.mode', [133, 351, 15.5, 10], ['type' => 'checkbox', 'checkbox' => ['match' => 'road', 'mark' => '✓']]),
                    $this->f('mode_rail', 'Journey Mode: RAIL', 1, 'form.mode', [189.5, 351, 15.5, 10], ['type' => 'checkbox', 'checkbox' => ['match' => 'rail', 'mark' => '✓']]),
                    $this->f('mode_air', 'Journey Mode: AIR', 1, 'form.mode', [242, 351, 15.5, 10], ['type' => 'checkbox', 'checkbox' => ['match' => 'air', 'mark' => '✓']]),
                    $this->f('travel_advance', '(a) Travel Advance', 1, 'form.advance_requested', [405, 400, 141, 14], $this->amount()),
                    $this->f('total_advance', 'Total Advance requested', 1, 'form.advance_requested', [405, 463, 142, 14], $this->amount()),
                    $this->f('dated', 'Date', 1, 'workflow.submitted_at', [45, 576, 98, 14], $this->date()),
                    $this->f('forwarded_by', 'Forwarded by HoD/Section Head', 1, 'approval.hod.name', [183, 600, 130, 14], ['required' => true]),
                    $this->statement('hod_record', 'HoD (record)', 1, 'hod', [320, 596, 240, 22], 6.5),
                    $this->f('registrar_date', 'Registrar: Date', 1, 'approval.sanction.date', [425, 654, 99, 14], $this->date()),
                    $this->statement('registrar_record', 'Registrar (record)', 1, 'sanction', [24, 667, 530, 11], 6.5),
                    $this->f('amount_sanctioned', 'Amount sanctioned', 1, 'form.advance_sanctioned', [80, 747, 92, 14], $this->amount()),
                    $this->f('amount_words', 'Amount in words', 1, 'form.advance_sanctioned', [252, 747, 302, 14], ['format' => ['kind' => 'words'], 'min_font_size' => 5]),
                ],
            ],
            [
                'key' => 'ta-bill', 'file' => 'TA Claim _ Settlement Form.pdf', 'name' => 'Traveling Allowance Bill (TA claim / settlement)',
                'description' => 'Issued when a TA/DA claim is paid. Journey legs are not captured by the claim workflow and stay blank.',
                'workflow' => 'tada-claim', 'output' => $financeGroups,
                'mapping' => [
                    $this->f('name', 'Name', 1, 'applicant.name', [78, 104, 235, 14], ['required' => true]),
                    $this->f('pf_no', 'Roll No/PF No.', 1, 'applicant.employee_id', [398, 107, 158, 12], ['font_size' => 9]),
                    $this->f('designation', 'Designation', 1, 'applicant.designation', [104, 129, 66, 18], $this->narrow()),
                    $this->f('department', 'Department', 1, 'applicant.department', [232, 129, 84, 18], $this->narrow()),
                    $this->f('purpose', 'Purpose of Journey', 1, 'form.journey', [145, 159, 410, 14]),
                    $this->f('total_claim', 'Total Claim', 1, 'form.claimed_amount', [180, 527, 65, 14], $this->amount(['font_size' => 9])),
                    $this->f('advance', 'Advance Drawn', 1, 'form.advance', [350, 527, 50, 14], $this->amount(['font_size' => 9, 'min_font_size' => 5])),
                    $this->f('net_claim', 'Net Claim', 1, 'form.net_claim', [470, 527, 73, 14], $this->amount(['font_size' => 9])),
                    $this->f('dated', 'Date', 1, 'workflow.submitted_at', [287, 577, 40, 14], ['format' => ['kind' => 'date', 'pattern' => 'd/m/y'], 'font_size' => 9, 'min_font_size' => 6]),
                    $this->f('pay_rupees', 'Pay Rupees', 1, 'form.admissible_amount', [110, 606, 440, 14], ['format' => ['kind' => 'words'], 'font_size' => 9]),
                    $this->statement('sanction_record', 'Finance sanction (record)', 1, 'sanction', [45, 672, 520, 22], 7),
                ],
            ],
            [
                'key' => 'medical-reimbursement', 'file' => 'Mediclaim Form.pdf', 'name' => 'Medical Reimbursement Application Form',
                'description' => 'Issued when a medical claim is paid. Confidential: the applicant, the deciding officers and named officers only. Certificate-A is completed by the medical adviser.',
                'workflow' => 'medical-claim', 'output' => $financeGroups + ['confidential' => true],
                'mapping' => [
                    $this->f('pf_no', 'P.F. No./Roll No', 1, 'applicant.employee_id', [440, 99, 83, 14]),
                    $this->f('name_line', '1. Name, Designation, Department', 1, 'literal', [245, 114, 300, 14], ['text' => '{{ applicant.name }}, {{ applicant.designation }}, {{ applicant.department }}', 'required' => true]),
                    $this->f('patient', '4. Patient and relationship', 1, 'literal', [95, 217, 450, 14], ['text' => '{{ form.patient_name }} ({{ form_label.patient }})']),
                    $this->f('total', '6. Total amount claimed', 1, 'form.total', [265, 535, 125, 14], $this->amount(['required' => true])),
                    $this->f('dated', 'Dated', 1, 'workflow.submitted_at', [100, 747, 90, 14], $this->date()),
                    $this->f('passed', '(a) Total amount of claim passed', 2, 'form.admissible_amount', [270, 678, 145, 14], $this->amount()),
                    $this->f('net_payable', '(c) Net amount payable', 2, 'form.admissible_amount', [270, 702, 150, 14], $this->amount()),
                    $this->f('please_pay', 'Please Pay Rs.', 2, 'form.admissible_amount', [139, 752, 80, 14], $this->amount(['font_size' => 9])),
                    $this->f('please_pay_words', '(Rupees ...)', 2, 'form.admissible_amount', [286, 752, 254, 14], ['format' => ['kind' => 'words'], 'min_font_size' => 5]),
                    $this->statement('sanction_record', 'Finance sanction (record)', 2, 'sanction', [72, 772, 450, 22], 6.5),
                ],
            ],
            [
                'key' => 'advance-settlement-f2', 'file' => 'Advance Settlement Form.pdf', 'name' => 'Statement of Accounts of Consumable / Contingency Advance (F-2)',
                'description' => 'Issued when an advance settlement is approved.',
                'workflow' => 'advance-settlement', 'output' => $financeGroups,
                'mapping' => [
                    $this->f('department', 'Department/Section', 1, 'applicant.department', [197, 91, 347, 13]),
                    $this->f('name', 'Advance in favour of (name)', 1, 'applicant.name', [247, 110, 297, 13], ['required' => true]),
                    $this->f('employee_no', 'Employee No.', 1, 'applicant.employee_id', [154, 129, 155, 13]),
                    $this->f('designation', 'Designation', 1, 'applicant.designation', [392, 129, 151, 13]),
                    $this->f('purpose', 'Purpose for which advance drawn', 1, 'form.advance_purpose', [290, 147, 254, 13], ['required' => true]),
                    $this->f('drawn_on', 'Date of advance drawn', 1, 'form.advance_drawn_on', [222, 160, 109, 13], $this->date()),
                    $this->f('reference', 'Adv. Ref. No.', 1, 'form.advance_reference', [411, 160, 130, 13]),
                    $this->f('amount_drawn', 'Amount of advance drawn', 1, 'form.advance_amount', [241, 178, 297, 13], $this->amount(['required' => true])),
                    $this->f('excess', 'Amount of excess claimed', 1, 'form.excess_claimed', [366, 197, 173, 13], $this->amount()),
                    $this->f('balance', 'Balance deposited (amount)', 1, 'form.balance_refundable', [263, 216, 110, 13], $this->amount()),
                    $this->f('balance_ref', 'Balance deposited (reference)', 1, 'form.refund_reference', [380, 216, 165, 13], ['format' => ['kind' => 'auto', 'prefix' => 'Ref. ']]),
                    $this->f('expenses', 'Expense statement', 1, 'form.expenses', [64.5, 283, 483, 26.2], ['type' => 'table', 'font_size' => 8.5, 'min_font_size' => 6, 'table' => [
                        'row_height' => 26.2, 'max_rows' => 11, 'columns' => [
                            ['path' => '#', 'x' => 0, 'w' => 36.5, 'align' => 'C'],
                            ['path' => 'date', 'x' => 36.5, 'w' => 33, 'align' => 'C', 'format' => ['kind' => 'date', 'pattern' => 'd/m/y']],
                            ['path' => 'details', 'x' => 228, 'w' => 91.5],
                            ['path' => 'amount', 'x' => 319.5, 'w' => 42, 'align' => 'R', 'format' => ['kind' => 'amount']],
                        ],
                    ]]),
                ],
            ],
            [
                'key' => 'technical-verification', 'file' => 'Technical Verification_Installation Form.pdf', 'name' => 'Technical Verification / Installation Report',
                'description' => 'Issued when goods are accepted at inspection.',
                'workflow' => 'goods-receipt', 'output' => ['download_groups' => ['applicant', 'participants', 'records'], 'required_steps' => ['inspection']],
                'mapping' => [
                    $this->f('members', 'Members of the inspection committee', 1, 'approval.inspection.name', [212, 130, 135, 17], ['required' => true]),
                    $this->f('items', 'Items inspected', 1, 'literal', [72, 170, 380, 17], ['text' => 'Goods received against purchase order {{ form.order_number }}']),
                    $this->f('specification', 'Items purchased (specification lines)', 1, 'form.received_items', [72, 345, 463, 19.6], ['type' => 'table', 'font_size' => 10, 'table' => [
                        'row_height' => 19.6, 'max_rows' => 9, 'columns' => [
                            ['path' => 'description', 'x' => 0, 'w' => 330],
                            ['path' => 'accepted', 'x' => 340, 'w' => 120, 'format' => ['kind' => 'auto', 'prefix' => 'Accepted: ']],
                        ],
                    ]]),
                    $this->statement('inspection_record', 'Inspection (record)', 1, 'inspection', [72, 600, 463, 24], 8),
                ],
            ],

            // Registered for mapping: no workflow for these processes yet.
            ['key' => 'long-leave', 'file' => 'Application for Long Leave (PDF).pdf', 'name' => 'Application for Long Leave (Annexure-I)', 'description' => 'Leave of three months or more. Needs the HoD recommendation, thesis/project arrangements and clearances (DORC, F&A, House Allotment) that the leave workflow does not capture yet.'],
            ['key' => 'cpda', 'file' => 'Cumulative Professional Development Allowance (CPDA) Form.pdf', 'name' => 'CPDA: conference permission (Annexure-I) and contingency purchase (Annexure-II)', 'description' => 'Needs a CPDA workflow with the CPDA account.'],
            ['key' => 'event-application', 'file' => 'Application Form for conducting Workshop_Conference_FDP_Seminar_Event, etc.pdf', 'name' => 'Application for conducting Workshop / Conference / FDP / Seminar / Event', 'description' => 'Needs an event approval workflow (HoD, DoRC, Director).'],
            ['key' => 'abroad-undertaking', 'file' => 'Undertaking for Abroad Visit Form.pdf', 'name' => 'Undertaking for Abroad Visit (F-4)', 'description' => 'Scanned original with the Director approval block. A typed copy without that block is kept as "Undertaking for Abroad Visit Form (35).pdf". Needs a foreign-visit workflow.'],
            ['key' => 'reimbursement-requisition', 'file' => 'Requisition for Reimbursement.pdf', 'name' => 'Requisition for Reimbursement (F-2) with GFR certificates', 'description' => 'Needs a general reimbursement workflow.'],
            ['key' => 'local-conveyance', 'file' => 'Local Conveyance Form.pdf', 'name' => 'Local Conveyance Form', 'description' => 'Needs a local conveyance claim workflow.'],
            ['key' => 'honorarium-claim', 'file' => 'Honorarium Form.pdf', 'name' => 'Claims Form for Remuneration / Honorarium / Sitting Charges', 'description' => 'Needs an honorarium workflow (often for external persons).'],
            ['key' => 'ro-amc-reimbursement', 'file' => 'AMC of RO Reimbursement.pdf', 'name' => 'Claim for Reimbursement of AMC of RO', 'description' => 'Page 1 is the notification, page 2 the claim form. Needs a declaration-based claim workflow.'],
            ['key' => 'newspaper-reimbursement', 'file' => 'News Paper Reimbursement.pdf', 'name' => 'Claim for Reimbursement of Newspaper Expenses', 'description' => 'Page 1 is the notification, page 2 the claim form. Needs a declaration-based claim workflow.'],
            ['key' => 'stores-indent', 'file' => 'Indent Form.pdf', 'name' => 'Indent (issue from stores: assets / consumables)', 'description' => 'Issue of items from stores, not a purchase. Needs a stores-issue workflow.'],
            ['key' => 'mdp-booking', 'file' => 'MDP booking Form.pdf', 'name' => 'MDP Centre Room / Facility Booking Form', 'description' => 'Estate bookings (Phase 7).'],
            ['key' => 'board-room-requisition', 'file' => 'Requisition for Board Room.pdf', 'name' => 'Requisition for Board Room', 'description' => 'Estate bookings (Phase 7). The page holds two copies of the form.'],
        ];
    }
}
