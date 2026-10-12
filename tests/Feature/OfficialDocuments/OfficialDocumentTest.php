<?php

namespace Tests\Feature\OfficialDocuments;

use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Documents\Models\Document;
use App\Modules\Notifications\Models\NotificationMessage;
use App\Modules\OfficialDocuments\Jobs\GenerateOfficialDocument;
use App\Modules\OfficialDocuments\Models\OfficialDocument;
use App\Modules\OfficialDocuments\Models\PdfTemplateVersion;
use App\Modules\OfficialDocuments\Services\OfficialDocumentService;
use App\Modules\OfficialDocuments\Services\TemplateService;
use App\Modules\Workflow\Engine\WorkflowEngine;
use App\Modules\Workflow\Engine\WorkflowFinished;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\OfficialDocumentFixtures;
use Tests\TestCase;

/** DOC-004..006: the acceptance tests of the official PDF brief, §19. */
class OfficialDocumentTest extends TestCase
{
    use OfficialDocumentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOfficialDocuments();
    }

    // Test 1, 6, 7, 12
    public function test_final_approval_generates_the_form_from_its_template_for_the_applicant(): void
    {
        $leave = $this->applyForLeave();
        $doc = $this->officialDoc($leave);
        $this->assertSame('waiting_approval', $doc->status);
        $this->assertSame($this->template->published->id, $doc->pdf_template_version_id, 'template version fixed at submission');

        $this->act($leave, $this->hod, remarks: 'Sanctioned');
        $doc->refresh();
        $this->assertSame(['generated', 1], [$doc->status, $doc->attempts]);
        $this->assertSame('Leave_Application_'.str_replace('/', '-', $leave->fresh()->reference).'_Approved.pdf', $doc->filename);
        $this->assertSame(hash_file('sha256', $this->storedPath($doc)), $doc->sha256);
        $this->assertTrue(NotificationMessage::query()->where('user_id', $this->initiator->id)->where('template_key', 'official_document.ready')->exists());

        $response = $this->actingAs($this->initiator)->get("/official-documents/{$doc->uuid}/download")->assertOk();
        $this->assertStringContainsString($doc->filename, $response->headers->get('Content-Disposition'));
        $this->assertTrue(AuditEvent::query()->where('action', 'official_document.downloaded')->where('actor_id', $this->initiator->id)->exists());

        // Values in their boxes (Test 7).
        $words = $this->words($this->storedPath($doc));
        $this->assertWordInBox($words, 'Sharma', $this->field('name'));
        $this->assertWordInBox($words, '02/11/2026', $this->field('from_date'));
        $this->assertWordInBox($words, '03/11/2026', $this->field('to_date'));
        $this->assertWordInBox($words, 'function', $this->field('reason'));
        $this->assertWordInBox($words, $this->hod->name === '' ? 'x' : explode(' ', $this->hod->name)[0], $this->field('sanctioned_by'));
        $this->assertWordInBox($words, 'Sanctioned', $this->field('sanction_remarks'));
        $this->assertWordInBox($words, 'Casual', $this->field('leave_type')); // the label, not the code "CL"
    }

    // Test 2, 3
    public function test_nothing_is_released_before_final_approval_or_after_rejection(): void
    {
        $leave = $this->applyForLeave();
        $doc = $this->officialDoc($leave);
        $this->actingAs($this->initiator)->get("/official-documents/{$doc->uuid}/download")->assertNotFound();
        $this->get('/official-documents')->assertInertia(fn ($p) => $p->where('documents.0.status_label', 'Waiting for final approval')->where('documents.0.download', null));

        $this->act($leave, $this->hod, 'reject', remarks: 'Exams week');
        $this->assertSame('not_generated', $doc->fresh()->status);
        $this->assertNull($doc->fresh()->document_id);
        $this->get("/official-documents/{$doc->uuid}/download")->assertNotFound();
        $this->assertTrue(AuditEvent::query()->where('action', 'official_document.download_denied')->exists());
    }

    // Test 4
    public function test_a_returned_request_gets_its_document_only_after_the_approval_completes(): void
    {
        $leave = $this->applyForLeave();
        $this->act($leave, $this->hod, 'return', remarks: 'Mention who takes your classes');
        $this->actingAs($this->initiator)->get('/official-documents')->assertInertia(fn ($p) => $p->where('documents.0.status_label', 'Returned for clarification')->where('documents.0.download', null));
        $this->assertSame('waiting_approval', $this->officialDoc($leave)->status);

        app(WorkflowEngine::class)->resubmit($leave->fresh(), $this->initiator, $leave->fresh()->data + ['arrangement' => 'Dr. Rao takes the classes'], 'Added');
        $this->assertSame('waiting_approval', $this->officialDoc($leave)->status);
        $this->act($leave, $this->hod);
        $this->assertSame('generated', $this->officialDoc($leave)->status);
    }

    // Test 5
    public function test_nobody_else_can_download_by_guessing(): void
    {
        $leave = $this->applyForLeave();
        $this->act($leave, $this->hod);
        $doc = $this->officialDoc($leave);

        $this->actingAs($this->person('Someone else', 'staff', $this->cse))->get("/official-documents/{$doc->uuid}/download")->assertNotFound();
        $this->get('/official-documents/'.Str::uuid().'/download')->assertNotFound();
        $this->get('/official-documents/'.$doc->id.'/download')->assertNotFound();
        $this->get('/official-documents/'.urlencode($leave->fresh()->reference).'/download')->assertNotFound();
        $this->get('/documents/'.$doc->file()->value('uuid'))->assertForbidden(); // the generic file route never serves it

        // A system administrator holds every permission except those to read others' records.
        $this->assertTrue($this->designer->hasRole('system_admin'));
        $this->actingAs($this->designer)->get("/official-documents/{$doc->uuid}/download")->assertNotFound();
        $this->actingAs($this->designer)->get('/admin/official-documents')->assertOk(); // may monitor, not read

        $this->actingAs(tap($this->person('Records officer'))->assignRole('records_officer'))->get("/official-documents/{$doc->uuid}/download")->assertOk();
        $this->actingAs($this->hod)->get("/official-documents/{$doc->uuid}/download")->assertNotFound(); // "participants" not configured for this output
    }

    // Test 8
    public function test_the_original_layout_is_untouched_outside_the_filled_boxes(): void
    {
        $leave = $this->applyForLeave();
        $this->act($leave, $this->hod);
        $doc = $this->officialDoc($leave);
        $version = $doc->templateVersion;
        $original = Storage::disk('private')->path($version->original()->value('path'));

        $raster = function (string $pdf, int $page): \GdImage {
            $out = tempnam(sys_get_temp_dir(), 'r');
            (new Process(['pdftoppm', '-png', '-r', '36', '-f', (string) $page, '-l', (string) $page, '-singlefile', $pdf, $out]))->mustRun();

            return imagecreatefrompng($out.'.png');
        };
        $scale = 36 / 72;
        for ($page = 1; $page <= $version->page_count; $page++) {
            [$a, $b] = [$raster($original, $page), $raster($this->storedPath($doc), $page)];
            $this->assertSame([imagesx($a), imagesy($a)], [imagesx($b), imagesy($b)], "page {$page} size");
            $boxes = array_filter($version->mapping, fn ($f) => $f['page'] === $page);
            [$differ, $outside] = [0, 0];
            for ($x = 0; $x < imagesx($a); $x++) {
                for ($y = 0; $y < imagesy($a); $y++) {
                    $inBox = false;
                    foreach ($boxes as $f) {
                        if ($x >= ($f['x'] - 2) * $scale && $x <= ($f['x'] + $f['w'] + 2) * $scale && $y >= ($f['y'] - 2) * $scale && $y <= ($f['y'] + $f['h'] + 2) * $scale) {
                            $inBox = true;
                            break;
                        }
                    }
                    if (! $inBox) {
                        $outside++;
                        $differ += (int) (imagecolorat($a, $x, $y) !== imagecolorat($b, $x, $y));
                    }
                }
            }
            $this->assertSame(0, $differ, "page {$page}: {$differ} of {$outside} pixels outside the boxes changed");
        }
    }

    // Test 9
    public function test_a_new_template_version_never_changes_issued_documents_or_pinned_requests(): void
    {
        $first = $this->applyForLeave();
        $this->act($first, $this->hod);
        $issued = $this->officialDoc($first);
        $hash = $issued->sha256;
        $pending = $this->applyForLeave(['from_date' => '2026-11-09', 'to_date' => '2026-11-09']); // submitted under v1

        $service = app(TemplateService::class);
        [$file] = $this->leaveForm('Leave Application (revised).pdf');
        $v2 = $service->addVersion($this->template, $file, $this->designer, 'Revised by office order');
        $this->assertEquals($this->mapping, $v2->mapping, 'mapping carried forward');
        $service->publish($v2, $this->designer);

        $this->assertSame([$hash, 1], [$issued->fresh()->sha256, $issued->fresh()->templateVersion->version]);
        $this->assertSame($hash, hash_file('sha256', $this->storedPath($issued)));
        $this->assertSame('superseded', PdfTemplateVersion::query()->where('version', 1)->value('status'));

        $this->act($pending, $this->hod);
        $this->assertSame(1, $this->officialDoc($pending)->templateVersion->version, 'keeps the version it was submitted with');
        $later = $this->applyForLeave(['from_date' => '2026-11-16', 'to_date' => '2026-11-16']);
        $this->assertSame(2, $this->officialDoc($later)->templateVersion->version);

        $this->expectException(\LogicException::class);
        PdfTemplateVersion::query()->where('version', 1)->sole()->forceFill(['mapping' => []])->save();
    }

    // Test 10
    public function test_failures_are_recorded_keep_the_approval_and_can_be_retried(): void
    {
        $leave = $this->applyForLeave();
        $version = $this->template->published;
        $working = Storage::disk('private')->path($version->working_path);
        rename($working, $working.'.away'); // a temporary storage problem

        $this->act($leave, $this->hod);
        $doc = $this->officialDoc($leave);
        $this->assertSame(['failed', 'template_missing'], [$doc->status, $doc->error_code]);
        $this->assertSame(['completed', 'approved'], [$leave->fresh()->status, $leave->fresh()->outcome], 'the approval stands');
        $this->actingAs($this->initiator)->get("/official-documents/{$doc->uuid}/download")->assertNotFound();
        $this->get('/official-documents')->assertInertia(fn ($p) => $p->where('documents.0.status_label', 'Delayed: the office has been informed')->missing('documents.0.error_detail'));
        $this->assertTrue(NotificationMessage::query()->where('user_id', $this->designer->id)->where('template_key', 'official_document.failed')->exists());
        $this->assertTrue(AuditEvent::query()->where('action', 'official_document.generation_failed')->exists());

        $this->actingAs($this->designer)->get('/admin/official-documents?status=failed')->assertInertia(fn ($p) => $p->where('documents.data.0.error_code', 'template_missing'));
        rename($working.'.away', $working);
        $this->post("/admin/official-documents/{$doc->uuid}/retry")->assertSessionHas('success');
        $this->assertSame(['generated', 2], [$doc->fresh()->status, $doc->fresh()->attempts]);

        // An infrastructure error that outlives the queue's retries is recorded too.
        $other = $this->applyForLeave(['from_date' => '2026-11-10', 'to_date' => '2026-11-10']);
        $row = $this->officialDoc($other);
        $row->forceFill(['status' => 'generating'])->save();
        (new GenerateOfficialDocument($row->id))->failed(new RuntimeException('disk full'));
        $this->assertSame(['failed', 'internal'], [$row->fresh()->status, $row->fresh()->error_code]);
    }

    // Test 11
    public function test_duplicate_events_and_jobs_do_not_create_a_second_document(): void
    {
        $leave = $this->applyForLeave();
        $this->act($leave, $this->hod);
        $doc = $this->officialDoc($leave);
        $files = Document::query()->where('category', 'official_document')->count();

        event(new WorkflowFinished($leave->id, 'completed', 'approved'));
        app(OfficialDocumentService::class)->generate($doc->id);
        (new GenerateOfficialDocument($doc->id))->handle(app(OfficialDocumentService::class));

        $this->assertSame(1, OfficialDocument::query()->where('workflow_instance_id', $leave->id)->count());
        $this->assertSame($files, Document::query()->where('category', 'official_document')->count());
        $this->assertSame([1, $doc->sha256], [$doc->fresh()->attempts, $doc->fresh()->sha256]);
    }

    // Test 13
    public function test_a_signature_requirement_holds_the_document_and_approval_is_not_presented_as_a_signature(): void
    {
        $this->output->forceFill(['requires_signature' => true])->save();
        $leave = $this->applyForLeave();
        $this->act($leave, $this->hod);
        $doc = $this->officialDoc($leave);

        $this->assertSame(['awaiting_signature', 'signature_provider_missing'], [$doc->status, $doc->error_code]);
        $this->actingAs($this->initiator)->get("/official-documents/{$doc->uuid}/download")->assertNotFound();
        $this->get('/official-documents')->assertInertia(fn ($p) => $p->where('documents.0.status_label', 'Awaiting authorised signature'));

        $pdf = file_get_contents($this->storedPath($doc));
        $this->assertStringNotContainsString('/ByteRange', $pdf);
        $this->assertStringNotContainsString('/Type /Sig', $pdf);
        $text = (new Process(['pdftotext', $this->storedPath($doc), '-']))->mustRun()->getOutput();
        $this->assertStringContainsString('not a signature', preg_replace('/\s+/', ' ', $text));
    }

    public function test_authorised_reissue_keeps_the_earlier_document(): void
    {
        $leave = $this->applyForLeave();
        $this->act($leave, $this->hod);
        $first = $this->officialDoc($leave);

        $this->actingAs($this->designer)->post("/admin/official-documents/{$first->uuid}/regenerate", ['reason' => 'short'])->assertSessionHasErrors('reason');
        $this->post("/admin/official-documents/{$first->uuid}/regenerate", ['reason' => 'Department name corrected in the directory'])->assertSessionHas('success');

        $second = $this->officialDoc($leave);
        $this->assertSame(['superseded', 'generated', 2, $first->id], [$first->fresh()->status, $second->status, $second->document_version, $second->supersedes_id]);
        $this->assertStringEndsWith('_v2.pdf', $second->filename);
        $this->actingAs($this->initiator)->get("/official-documents/{$first->uuid}/download")->assertNotFound();
        $this->get("/official-documents/{$second->uuid}/download")->assertOk();
        $this->actingAs(tap($this->person('Records'))->assignRole('records_officer'))->get("/official-documents/{$first->uuid}/download")->assertOk();
        $this->assertTrue(AuditEvent::query()->where('action', 'official_document.regeneration_requested')->exists());
    }

    public function test_a_condition_on_the_output_and_missing_required_approvals_are_respected(): void
    {
        $this->output->forceFill(['condition' => ['field' => 'leave_type', 'op' => 'eq', 'value' => 'EL']])->save();
        $leave = $this->applyForLeave();
        $this->act($leave, $this->hod);
        $this->assertSame(['not_generated', 'condition_not_met'], [$this->officialDoc($leave)->status, $this->officialDoc($leave)->error_code]);

        $this->output->forceFill(['condition' => null, 'required_steps' => ['registrar']])->save(); // a step this short leave never visits
        $leave = $this->applyForLeave(['from_date' => '2026-11-12', 'to_date' => '2026-11-12']);
        $this->act($leave, $this->hod);
        $this->assertSame(['failed', 'approvals_incomplete'], [$this->officialDoc($leave)->status, $this->officialDoc($leave)->error_code]);
    }
}
