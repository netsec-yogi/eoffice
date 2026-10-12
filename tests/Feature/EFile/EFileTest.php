<?php

namespace Tests\Feature\EFile;

use App\Modules\Documents\Models\Document;
use App\Modules\EFile\Models\EFile;
use App\Modules\EFile\Models\EFileNote;
use App\Modules\EFile\Models\FileCategory;
use App\Modules\EFile\Models\RetentionSchedule;
use App\Modules\EFile\Services\EFileService;
use App\Modules\EFile\Services\FileAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Engine\WorkflowException;
use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Support\BuildsWorkflows;
use Tests\TestCase;

/** FILE-001 .. FILE-006 */
class EFileTest extends TestCase
{
    use BuildsWorkflows;

    private FileCategory $category;

    private EFileService $files;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('private');
        $this->setUpOrganisation();
        $schedule = RetentionSchedule::create(['code' => 'R5', 'name' => 'Five years', 'retain_years' => 5, 'action' => 'destroy']);
        $this->category = FileCategory::create(['code' => 'EST', 'name' => 'Establishment', 'numbering_pattern' => '{DEPT}/{CAT}/{YYYY}/{SEQ:3}', 'default_classification' => 'internal', 'retention_schedule_id' => $schedule->id]);
        $this->files = app(EFileService::class);
    }

    private function open(User $by, string $class = 'internal'): EFile
    {
        return $this->files->open(['subject' => 'Purchase of lab equipment', 'file_category_id' => $this->category->id, 'department_id' => $by->department_id, 'classification' => $class], $by);
    }

    private function pdf(string $name = 'quote.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\n%%EOF");

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_files_are_numbered_by_category_pattern(): void
    {
        $year = now()->format('Y');
        $this->assertSame("CSE/EST/{$year}/001", $this->open($this->initiator)->file_number);
        $this->assertSame("CSE/EST/{$year}/002", $this->open($this->hod)->file_number);
    }

    public function test_files_can_only_be_opened_for_ones_own_department(): void
    {
        $this->expectException(WorkflowException::class);
        $this->files->open(['subject' => 'x', 'file_category_id' => $this->category->id, 'department_id' => $this->ece->id, 'classification' => 'internal'], $this->initiator);
    }

    public function test_notes_are_private_drafts_until_finalised_then_numbered_and_immutable(): void
    {
        $file = $this->open($this->initiator);
        $draft = $this->files->draftNote($file, $this->initiator, ['kind' => 'note', 'body' => 'Quotations from three vendors are placed below.']);
        $this->assertNull($draft->number);

        $this->actingAs($this->hod);
        $file->forceFill(['classification' => 'public'])->saveQuietly();
        $this->get("/files/{$file->id}")->assertInertia(fn ($p) => $p->has('contents.notes', 0));

        $note = $this->files->finalizeNote($draft, $this->initiator);
        $this->assertSame(1, $note->number);
        $this->assertTrue($note->intact());
        $this->get("/files/{$file->id}")->assertInertia(fn ($p) => $p->where('contents.notes.0.number', 1));

        try {
            $note->forceFill(['body' => 'edited later'])->save();
            $this->fail('model must refuse');
        } catch (LogicException) {
        }
        $this->expectException(QueryException::class);
        DB::table('efile_notes')->where('id', $note->id)->update(['body' => 'edited behind the app']);
    }

    public function test_corrections_are_addenda_to_finalised_notes(): void
    {
        $file = $this->open($this->initiator);
        $first = $this->files->finalizeNote($this->files->draftNote($file, $this->initiator, ['body' => 'Amount is Rs 1,20,000.']), $this->initiator);
        $fix = $this->files->finalizeNote($this->files->draftNote($file, $this->initiator, ['body' => 'Read Rs 1,02,000 in note 1.', 'corrects_note_id' => $first->id]), $this->initiator);

        $this->assertSame(['addendum', 2, $first->id], [$fix->kind, $fix->number, $fix->corrects_note_id]);
        $this->assertSame('Amount is Rs 1,20,000.', $first->fresh()->body);
    }

    public function test_file_moves_only_on_acknowledgement_and_can_be_recalled(): void
    {
        $file = $this->open($this->initiator);
        $note = $this->files->finalizeNote($this->files->draftNote($file, $this->initiator, ['kind' => 'recommendation', 'body' => 'May approve.']), $this->initiator);

        $move = $this->files->send($file, $this->initiator, $this->hod, 'approval', 'Please approve', $note->id);
        $this->assertSame($this->initiator->id, $file->fresh()->custodian_id, 'custody unchanged while in transit');
        $this->assertFalse(app(FileAccess::class)->canAct($this->initiator, $file->fresh()), 'nothing can be written in transit');

        try {
            $this->files->send($file, $this->initiator, $this->registrar, 'comments', null, null);
            $this->fail('cannot send twice');
        } catch (WorkflowException $e) {
            $this->assertSame(409, $e->status);
        }

        $this->files->recall($move, $this->initiator, 'Forgot an enclosure');
        $move2 = $this->files->send($file, $this->initiator, $this->hod, 'approval', 'Please approve', $note->id);

        $this->expectExceptionObject(new WorkflowException('This file is not waiting for you.', 409));
        try {
            $this->files->receive($move2, $this->registrar);
        } finally {
            $this->files->receive($move2, $this->hod, 'Received');
            $this->assertSame($this->hod->id, $file->fresh()->custodian_id);
            $this->assertEqualsCanonicalizing(['opened', 'note_finalised', 'sent', 'recalled', 'sent', 'received'], $file->events()->pluck('event')->all());
        }
    }

    public function test_only_one_movement_can_be_in_transit_even_bypassing_the_service(): void
    {
        $file = $this->open($this->initiator);
        $this->files->send($file, $this->initiator, $this->hod, 'comments', null, null);

        $this->expectException(QueryException::class);
        DB::table('efile_movements')->insert(['efile_id' => $file->id, 'from_user_id' => $this->initiator->id, 'to_user_id' => $this->registrar->id, 'required_action' => 'comments', 'status' => 'in_transit', 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_approval_by_decision_note_of_the_receiving_authority(): void
    {
        $file = $this->open($this->initiator);
        $move = $this->files->send($file, $this->initiator, $this->hod, 'approval', null, null);
        $this->files->receive($move, $this->hod);
        $this->files->finalizeNote($this->files->draftNote($file, $this->hod, ['kind' => 'decision', 'body' => 'Approved.']), $this->hod);

        $this->assertTrue($file->events()->where('event', 'decision_recorded')->exists());
        $this->assertDatabaseHas('audit_events', ['action' => 'efile.note_finalised', 'subject_id' => $file->id]);
    }

    public function test_classification_controls_who_can_read(): void
    {
        $outsider = $this->person('ECE staff', 'staff', $this->ece);
        $colleague = $this->person('CSE colleague', 'staff', $this->cse);
        $admin = tap($this->person('Admin'))->assignRole('system_admin');
        $access = app(FileAccess::class);

        $internal = $this->open($this->initiator, 'internal');
        $this->assertTrue($access->allows($colleague, $internal));
        $this->assertFalse($access->allows($outsider, $internal));

        $restricted = $this->open($this->initiator, 'restricted');
        $this->assertFalse($access->allows($colleague, $restricted));
        $this->assertFalse($access->allows($admin, $restricted), 'no blanket administrator access');

        // Participants and grants see it; the scope agrees with the check.
        $this->files->grant($restricted, $this->initiator, $outsider, 'Inquiry committee member', null);
        $this->assertTrue($access->allows($outsider, $restricted));
        foreach ([$colleague, $outsider, $admin, $this->hod] as $u) {
            $this->assertSame($access->allows($u, $restricted), $access->scope(EFile::query(), $u)->whereKey($restricted->id)->exists());
        }

        // An administrator holds the records permission: register metadata only, never contents.
        $this->actingAs($admin)->get("/files/{$restricted->id}")->assertOk()->assertInertia(fn ($p) => $p->where('contents', null));
        $this->actingAs($colleague)->get("/files/{$restricted->id}")->assertForbidden();
        $this->actingAs($outsider)->get("/files/{$restricted->id}")->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'efile.viewed', 'subject_id' => $restricted->id, 'actor_id' => $outsider->id]);
    }

    public function test_records_officers_see_metadata_not_contents(): void
    {
        $officer = tap($this->person('Records'))->assignRole('records_officer');
        $file = $this->open($this->initiator, 'confidential');
        $this->files->finalizeNote($this->files->draftNote($file, $this->initiator, ['body' => 'Sensitive']), $this->initiator);

        $this->actingAs($officer)->get("/files/{$file->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->where('contents', null)->where('file.subject', '(classified)'));
    }

    public function test_documents_inherit_file_access_and_are_versioned(): void
    {
        $file = $this->open($this->initiator, 'confidential');
        $v1 = $this->files->attach($file, $this->initiator, $this->pdf());
        $v2 = $this->files->attach($file, $this->initiator, $this->pdf('quote-revised.pdf'), 'enclosure', $v1);

        $this->assertSame([2, $v1->id, 'confidential'], [$v2->version, $v2->previous_version_id, $v2->classification]);
        $this->actingAs($this->hod)->get("/documents/{$v2->uuid}")->assertForbidden();
        $this->actingAs($this->initiator)->get("/documents/{$v2->uuid}")->assertOk();
    }

    public function test_routing_a_file_through_a_workflow_gives_approvers_access_and_records_the_outcome(): void
    {
        $definition = $this->workflow([
            ['start', 'start'],
            ['reg', 'approval', 'Registrar', ['assignees' => [['type' => 'authority', 'authority' => 'REGISTRAR']]]],
            ['ok', 'end', 'Approved'], ['no', 'end', 'Rejected', ['outcome' => 'rejected']],
        ], [['start', 'reg'], ['reg', 'ok', 'approve'], ['reg', 'no', 'reject']], category: 'file_approval', key: 'file-approval');
        $file = $this->open($this->initiator, 'confidential');

        $this->actingAs($this->initiator)->post('/requests/new/file-approval', ['data' => [], 'efile' => $file->id])->assertRedirect();
        $this->assertTrue(app(FileAccess::class)->allows($this->registrar, $file), 'approver can read the file');

        $this->approve(WorkflowInstance::query()->sole(), $this->registrar);
        $event = $file->events()->where('event', 'workflow_completed')->sole();
        $this->assertStringContainsString('approved', $event->summary);

        // Someone who does not hold the file cannot route it.
        $this->actingAs($this->hod)->post('/requests/new/file-approval', ['data' => [], 'efile' => $file->id])->assertStatus(409);
    }

    public function test_closing_sets_retention_and_disposition_is_controlled(): void
    {
        $officer = tap($this->person('Records'))->assignRole('records_officer');
        $file = $this->open($this->initiator);
        $doc = $this->files->attach($file, $this->initiator, $this->pdf());
        $this->files->finalizeNote($this->files->draftNote($file, $this->initiator, ['body' => 'Done.']), $this->initiator);
        $this->files->close($file, $this->initiator, 'Purchase complete');
        $this->assertSame(now()->addYears(5)->toDateString(), $file->fresh()->retain_until->toDateString());

        try {
            $this->files->dispose($file->fresh(), $officer, 'destroy', 'early');
            $this->fail('not before the retention period ends');
        } catch (WorkflowException) {
        }

        $this->travel(5)->years();
        $this->travel(1)->days();
        $this->artisan('efiles:retention-review')->assertSuccessful();
        $this->assertSame('due', $file->fresh()->disposition);

        $this->files->setLegalHold($file->fresh(), $officer, true, 'Audit objection pending');
        try {
            $this->files->dispose($file->fresh(), $officer, 'destroy', 'schedule');
            $this->fail('legal hold blocks disposition');
        } catch (WorkflowException) {
        }
        $this->files->setLegalHold($file->fresh(), $officer, false, 'Objection settled');

        $this->files->dispose($file->fresh(), $officer, 'destroy', 'Retention schedule R5');
        $file->refresh();
        $this->assertSame('destroyed', $file->status);
        $this->assertSame(0, EFileNote::query()->where('efile_id', $file->id)->count());
        Storage::disk('private')->assertMissing($doc->path);
        $this->assertSame('destroyed', Document::query()->find($doc->id)->scan_status);
        $this->assertTrue($file->events()->where('event', 'disposition')->exists(), 'the record of destruction remains');
        $this->assertFalse(app(FileAccess::class)->allows($this->initiator, $file));
    }

    public function test_only_the_custodian_writes_on_the_file(): void
    {
        $file = $this->open($this->initiator, 'public');
        $this->expectException(WorkflowException::class);
        $this->files->draftNote($file, $this->hod, ['body' => 'Not my file']);
    }
}
