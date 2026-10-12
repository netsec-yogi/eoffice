<?php

namespace Tests\Feature\Registrar;

use App\Modules\Documents\Models\Document;
use App\Modules\Registrar\Models\Certificate;
use App\Modules\Registrar\Models\Circular;
use App\Modules\Registrar\Models\CircularReceipt;
use App\Modules\Registrar\Models\RtiRequest;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;
use Tests\Support\RegistrarFixtures;
use Tests\TestCase;

/** REG-002, REG-003, REG-004 */
class CertificatesCircularsRtiTest extends TestCase
{
    use RegistrarFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRegistrar();
    }

    public function test_noc_is_issued_as_numbered_pdf_and_verifiable(): void
    {
        $i = $this->start('noc-passport', $this->initiator, ['passport_purpose' => 'renewal of passport']);
        $this->act($i, $this->hod);
        $this->act($i, $this->registrar);

        $this->assertSame('completed', $i->fresh()->status);
        $c = Certificate::query()->sole();
        $this->assertMatchesRegularExpression('#^NOC/PP/2026/0001$#', $c->number);
        $this->assertStringContainsString('renewal of passport', $c->body);
        $this->assertSame([$this->registrar->name, $this->initiator->id], [$c->signatory_name, $c->user_id]);
        $pdf = Document::query()->findOrFail($c->document_id);
        $this->assertSame('application/pdf', $pdf->mime_type);

        // Holder and approvers can download it; others cannot.
        $this->actingAs($this->initiator)->get("/documents/{$pdf->uuid}")->assertOk();
        $this->actingAs($this->person('Other'))->get("/documents/{$pdf->uuid}")->assertForbidden();

        // Anyone can verify, without signing in, and sees only what is needed.
        auth()->logout();
        $this->get("/verify/{$c->verification_code}")->assertOk()->assertInertia(fn ($p) => $p->where('certificate.valid', true)->where('certificate.number', $c->number)->missing('certificate.body'));
        $this->actingAs($this->establishment)->post("/certificates/{$c->id}/revoke", ['reason' => 'Issued in error'])->assertSessionHas('success');
        auth()->logout();
        $this->get("/verify/{$c->verification_code}")->assertInertia(fn ($p) => $p->where('certificate.valid', false));
        $this->get('/verify/NOSUCHCODE123')->assertInertia(fn ($p) => $p->where('certificate', null));
    }

    public function test_circular_is_approved_published_distributed_and_acknowledged(): void
    {
        $cseMember = $this->person('CSE member', 'staff', $this->cse);
        $eceMember = $this->person('ECE member', 'staff', $this->ece);

        $this->actingAs($this->establishment)->post('/circulars', ['kind' => 'circular', 'title' => 'Fire drill', 'body' => 'A fire drill will be held on Friday.', 'departments' => [$this->cse->id], 'categories' => ['faculty', 'staff'], 'requires_ack' => true])->assertRedirect();
        $c = Circular::query()->sole();
        $this->post("/circulars/{$c->id}/submit", ['workflow' => 'circular-approval'])->assertSessionHas('success');
        $this->assertSame('in_review', $c->fresh()->status);
        $this->put("/circulars/{$c->id}", ['kind' => 'circular', 'title' => 'x', 'body' => 'y', 'categories' => ['staff']])->assertSessionHasErrors('workflow');

        $this->act(WorkflowInstance::query()->sole(), $this->registrar);
        $c->refresh();
        $this->assertSame('published', $c->status);
        $this->assertMatchesRegularExpression('#^CIR/2026/001$#', $c->number);
        $this->assertNotNull($c->document_id);
        $this->assertTrue(CircularReceipt::query()->where(['circular_id' => $c->id, 'user_id' => $cseMember->id])->exists());
        $this->assertFalse(CircularReceipt::query()->where(['circular_id' => $c->id, 'user_id' => $eceMember->id])->exists(), 'department-wise distribution');

        $this->actingAs($eceMember)->get("/circulars/{$c->id}")->assertForbidden();
        $this->actingAs($cseMember)->get("/circulars/{$c->id}")->assertOk();
        $this->assertNotNull(CircularReceipt::query()->where(['circular_id' => $c->id, 'user_id' => $cseMember->id])->value('read_at'));
        $this->post("/circulars/{$c->id}/acknowledge")->assertSessionHas('success');
        $this->actingAs($this->establishment)->get("/circulars/{$c->id}")->assertInertia(fn ($p) => $p->where('report', fn ($r) => collect($r)->firstWhere('department', 'CSE')['acknowledged'] === 1));

        // A revision supersedes the published version only once it is published.
        $this->post("/circulars/{$c->id}/revise")->assertRedirect();
        $v2 = Circular::query()->where('previous_version_id', $c->id)->sole();
        $this->assertSame('published', $c->fresh()->status);
        $this->post("/circulars/{$v2->id}/submit", ['workflow' => 'circular-approval']);
        $this->act(WorkflowInstance::query()->where('subject_id', $v2->id)->sole(), $this->registrar);
        $this->assertSame(['superseded', 'published', 2], [$c->fresh()->status, $v2->fresh()->status, $v2->fresh()->version]);
    }

    public function test_rejected_circular_returns_to_draft(): void
    {
        $this->actingAs($this->establishment)->post('/circulars', ['kind' => 'notice', 'title' => 'Holiday', 'body' => 'x', 'categories' => ['staff']]);
        $c = Circular::query()->sole();
        $this->post("/circulars/{$c->id}/submit", ['workflow' => 'circular-approval']);
        $this->act(WorkflowInstance::query()->sole(), $this->registrar, 'reject', remarks: 'Wrong date');
        $this->assertSame('draft', $c->fresh()->status);
    }

    public function test_rti_statutory_dates_pio_reply_and_appeal(): void
    {
        $pio = $this->person('PIO', 'staff');
        $base = ['applicant_name' => 'A. Citizen', 'applicant_address' => 'Somewhere', 'received_via' => 'post', 'subject' => 'Admissions data', 'information_sought' => 'Number of seats', 'pio' => $pio->id];

        $rti = $this->start('rti-request', $this->rtiClerk, $base + ['received_on' => '2026-10-15']);
        $this->assertSame('2026-11-14', $rti->data['due_on'], '30 days');
        $this->assertSame('2026-10-17', $this->start('rti-request', $this->rtiClerk, array_merge($base, ['received_on' => '2026-10-15', 'life_liberty' => true]))->data['due_on'], '48 hours');
        $this->assertSame('2026-11-19', $this->start('rti-request', $this->rtiClerk, array_merge($base, ['received_on' => '2026-10-15', 'received_via' => 'apio']))->data['due_on'], '+5 via APIO');

        $register = RtiRequest::query()->where('workflow_instance_id', $rti->id)->sole();
        $this->assertMatchesRegularExpression('#^RTI/2026/\d{4}$#', $register->registration_number);
        $task = WorkflowTask::query()->where('workflow_instance_id', $rti->id)->where('status', 'pending')->sole();
        $this->assertSame([$pio->id, '2026-11-14'], [$task->assignee_id, $task->due_at->toDateString()], 'PIO deadline is the statutory date');

        $this->act($rti, $pio, 'approve', ['outcome' => 'partly', 'reply_summary' => 'Seat matrix supplied; reservation roster exempt', 'denial_sections' => '8(1)(j)']);
        $this->act($rti, $this->rtiClerk, 'approve', ['dispatched_on' => '2026-11-02', 'dispatch_mode' => 'post']);
        $register->refresh();
        $this->assertSame(['replied', 'partly', '2026-11-02'], [$register->status, $register->outcome, $register->replied_on->toDateString()]);

        $this->travelTo('2026-11-21 11:00');
        $appeal = $this->start('rti-appeal', $this->rtiClerk, ['rti' => (string) $register->id, 'appeal_received_on' => '2026-11-20', 'grounds' => 'Incomplete reply']);
        $this->assertSame('2026-12-20', $appeal->data['appeal_due_on']);
        $this->assertSame('filed', $register->fresh()->appeal_status);
        $this->act($appeal, $this->faa, 'approve', ['decision' => 'partly', 'order' => 'Supply the roster with personal data redacted']);
        $this->assertSame('decided', $register->fresh()->appeal_status);

        $this->actingAs($this->rtiClerk)->get('/rti')->assertOk()->assertInertia(fn ($p) => $p->where('stats.open', 2));
        $this->actingAs($pio)->get('/rti')->assertForbidden();
    }
}
