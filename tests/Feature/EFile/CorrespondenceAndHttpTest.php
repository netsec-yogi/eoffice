<?php

namespace Tests\Feature\EFile;

use App\Modules\EFile\Models\Correspondence;
use App\Modules\EFile\Models\EFile;
use App\Modules\EFile\Models\FileCategory;
use App\Modules\EFile\Models\RetentionSchedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Phase 4 exit criterion over HTTP: a file is created, annotated, routed,
 * approved, transferred and retrieved with complete, authorised history;
 * correspondence is diarised and filed.
 */
class CorrespondenceAndHttpTest extends TestCase
{
    use BuildsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('private');
        $this->setUpOrganisation();
        $schedule = RetentionSchedule::create(['code' => 'PERM', 'name' => 'Permanent', 'retain_years' => null, 'action' => 'review']);
        FileCategory::create(['code' => 'GEN', 'name' => 'General', 'numbering_pattern' => '{DEPT}/{CAT}/{SEQ:4}', 'retention_schedule_id' => $schedule->id]);
    }

    private function pdf(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\n%%EOF");

        return new UploadedFile($path, 'letter.pdf', null, null, true);
    }

    public function test_file_lifecycle_over_http(): void
    {
        $clerk = tap($this->person('Registry clerk', 'staff', $this->cse))->assignRole('registry_clerk');

        // Incoming letter diarised and marked to the initiator.
        $this->actingAs($clerk)->post('/correspondence', [
            'direction' => 'incoming', 'kind' => 'letter', 'subject' => 'Offer of collaboration', 'counterparty' => 'Example University',
            'their_reference' => 'EU/2026/77', 'mode' => 'post', 'marked_to' => $this->initiator->id, 'documents' => [$this->pdf()],
        ])->assertSessionHas('success');
        $letter = Correspondence::query()->sole();
        $this->assertMatchesRegularExpression('#^DIARY/\d{4}/00001$#', $letter->register_number);

        // The initiator opens a file, puts the letter on it and writes a note.
        $this->actingAs($this->initiator)->post('/files', ['subject' => 'Collaboration with Example University', 'file_category_id' => FileCategory::query()->value('id'), 'department_id' => $this->cse->id, 'classification' => 'internal'])->assertRedirect();
        $file = EFile::query()->sole();
        $this->post("/correspondence/{$letter->id}/attach", ['efile_id' => $file->id])->assertSessionHas('success');
        $this->post("/files/{$file->id}/notes", ['kind' => 'recommendation', 'body' => 'The offer at DIARY above is recommended.', 'finalize' => true])->assertSessionHas('success');
        $this->post("/files/{$file->id}/documents", ['file' => $this->pdf()])->assertSessionHas('success');

        // Sent to the HoD for approval; nothing can be written while in transit.
        $this->post("/files/{$file->id}/send", ['to' => $this->hod->id, 'required_action' => 'approval', 'reason' => 'For approval', 'note_id' => $file->notes()->value('id')])->assertRedirect('/files');
        $this->post("/files/{$file->id}/notes", ['body' => 'late addition'])->assertSessionHasErrors('workflow');
        $this->actingAs($this->hod)->get('/files?tab=incoming')->assertInertia(fn ($p) => $p->where('files.total', 1)->where('counts.incoming', 1));

        $movement = $file->movements()->sole();
        $this->post("/file-movements/{$movement->id}/receive", ['remarks' => 'Received'])->assertRedirect("/files/{$file->id}");
        $this->post("/files/{$file->id}/notes", ['kind' => 'decision', 'body' => 'Approved. Registrar may sign the MoU.', 'finalize' => true]);

        // Transferred to the Registrar, who receives it and replies.
        $this->post("/files/{$file->id}/send", ['to' => $this->registrar->id, 'required_action' => 'action']);
        $this->actingAs($this->registrar)->post('/file-movements/'.$file->movements()->latest('id')->value('id').'/receive');
        $this->post('/correspondence', ['direction' => 'outgoing', 'kind' => 'letter', 'subject' => 'Acceptance of collaboration', 'counterparty' => 'Example University', 'mode' => 'email', 'efile_id' => $file->id])->assertSessionHas('success');

        // Retrieval: the full history is there for authorised readers.
        $this->actingAs($this->initiator)->get("/files/{$file->id}")->assertOk()->assertInertia(fn ($p) => $p
            ->where('file.custodian.id', $this->registrar->id)
            ->has('contents.notes', 2)
            ->has('contents.movements', 2)
            ->has('contents.correspondence', 2)
            ->where('contents.history', fn ($h) => collect($h)->pluck('event')->all() === ['opened', 'correspondence', 'note_finalised', 'document_added', 'sent', 'received', 'decision_recorded', 'sent', 'received', 'correspondence']));

        // People outside the department cannot.
        $this->actingAs($this->person('Outsider', 'staff', $this->ece))->get("/files/{$file->id}")->assertForbidden();
    }

    public function test_only_the_registry_diarises_and_outgoing_needs_a_held_file(): void
    {
        $this->actingAs($this->initiator)->post('/correspondence', ['direction' => 'incoming', 'kind' => 'letter', 'subject' => 'x', 'counterparty' => 'y', 'mode' => 'post'])
            ->assertSessionHasErrors('workflow');
        $this->actingAs($this->initiator)->post('/correspondence', ['direction' => 'outgoing', 'kind' => 'letter', 'subject' => 'x', 'counterparty' => 'y', 'mode' => 'post'])
            ->assertSessionHasErrors('efile_id');
    }

    public function test_records_pages_need_the_permission(): void
    {
        $this->actingAs($this->initiator)->get('/records')->assertForbidden();
        $this->actingAs($this->initiator)->get('/files?tab=register')->assertForbidden();
        $officer = tap($this->person('Records'))->assignRole('records_officer');
        $this->actingAs($officer)->get('/records')->assertOk();
        $this->actingAs($officer)->post('/records/categories', ['code' => 'FIN', 'name' => 'Finance', 'numbering_pattern' => 'FIN/{YYYY}/{SEQ:4}', 'default_classification' => 'confidential'])->assertSessionHas('success');
        $this->assertDatabaseHas('audit_events', ['action' => 'records.category.created']);
    }
}
