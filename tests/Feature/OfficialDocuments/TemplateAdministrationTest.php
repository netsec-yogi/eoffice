<?php

namespace Tests\Feature\OfficialDocuments;

use App\Modules\Audit\Models\AuditEvent;
use App\Modules\OfficialDocuments\Models\OfficialDocument;
use App\Modules\OfficialDocuments\Models\PdfTemplate;
use App\Modules\OfficialDocuments\Pdf\GenerationException;
use App\Modules\OfficialDocuments\Pdf\PdfRenderer;
use App\Modules\OfficialDocuments\Pdf\TemplateInspector;
use App\Modules\OfficialDocuments\Pdf\ValueFormatter;
use App\Modules\OfficialDocuments\Support\SyntheticTemplates;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Support\OfficialDocumentFixtures;
use Tests\TestCase;

/** DOC-001..003: managing templates, mapping fields, previews and the rules around them. */
class TemplateAdministrationTest extends TestCase
{
    use OfficialDocumentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOfficialDocuments();
    }

    public function test_only_template_administrators_reach_the_panel(): void
    {
        $this->actingAs($this->initiator)->get('/admin/pdf-templates')->assertForbidden();
        $admin = tap($this->person('Document admin'))->assignRole('document_administrator');
        $this->actingAs($admin)->get('/admin/pdf-templates')->assertOk()->assertInertia(fn ($p) => $p->component('Admin/PdfTemplates/Index')->where('templates.0.key', 'leave-application')->where('templates.0.workflows.0', 'Leave application'));
        $this->get("/admin/pdf-templates/{$this->template->id}")->assertOk()->assertInertia(fn ($p) => $p->component('Admin/PdfTemplates/Show')->has('versions', 1)->has('outputs', 1));
        $version = $this->template->published;
        $this->get("/admin/pdf-template-versions/{$version->id}/mapper")->assertOk()->assertInertia(fn ($p) => $p->component('Admin/PdfTemplates/Mapper')->where('version.editable', false)->has('version.mapping', count($this->mapping)));
        $png = $this->get("/admin/pdf-template-versions/{$version->id}/pages/1")->assertOk();
        $this->assertSame("\x89PNG", substr($png->getContent(), 0, 4));
        $this->get("/admin/pdf-template-versions/{$version->id}/pages/9")->assertNotFound();
        // Reading generated documents is a separate grant.
        $this->assertFalse($admin->can('official_documents.records'));
    }

    public function test_upload_rejects_what_is_not_a_usable_pdf(): void
    {
        $this->actingAs($this->designer);
        $this->post('/admin/pdf-templates', ['key' => 'x-form', 'name' => 'X', 'file' => UploadedFile::fake()->createWithContent('form.pdf', 'not a pdf at all')])->assertSessionHasErrors('file');
        $this->post('/admin/pdf-templates', ['key' => 'x-form', 'name' => 'X', 'file' => UploadedFile::fake()->createWithContent('form.txt', 'plain text')])->assertSessionHasErrors('file');
        $this->assertSame(1, PdfTemplate::query()->count());
    }

    public function test_interactive_fields_are_detected_and_filled_in_place(): void
    {
        $path = TemplateInspector::tempDir().'/fillable.pdf';
        SyntheticTemplates::fillable($path);
        $this->actingAs($this->designer)->post('/admin/pdf-templates', ['key' => 'certificate-request', 'name' => 'Certificate request', 'file' => new UploadedFile($path, 'Certificate Request.pdf', 'application/pdf', null, true)])->assertRedirect();
        $version = PdfTemplate::query()->where('key', 'certificate-request')->sole()->versions()->sole();
        $this->assertSame(['applicant_name', 'purpose', 'urgent'], array_column($version->form_fields, 'name'));
        $this->assertSame(['text', 'text', 'checkbox'], array_column($version->form_fields, 'type'));

        // Map the detected widgets: the field rectangles become the boxes.
        $byName = collect($version->form_fields)->keyBy('name');
        $box = fn (string $n) => array_intersect_key($byName[$n], array_flip(['page', 'x', 'y', 'w', 'h']));
        $mapping = [
            ['key' => 'name', 'type' => 'text', 'source' => 'applicant.name', 'font_size' => 10, 'min_font_size' => 6] + $box('applicant_name'),
            ['key' => 'purpose', 'type' => 'text', 'source' => 'literal', 'text' => 'Bank loan', 'font_size' => 10] + $box('purpose'),
            ['key' => 'urgent', 'type' => 'checkbox', 'source' => 'literal', 'text' => 'yes', 'checkbox' => ['match' => 'yes']] + $box('urgent'),
        ];
        $this->put("/admin/pdf-template-versions/{$version->id}/mapping", ['mapping' => $mapping])->assertSessionHas('success');

        $preview = $this->postJson("/admin/pdf-template-versions/{$version->id}/preview", ['mapping' => $mapping])->assertOk()->json('pages');
        $this->assertCount(1, $preview);
        $this->assertStringStartsWith('data:image/png;base64,', $preview[0]);

        $pdf = $this->get("/admin/pdf-template-versions/{$version->id}/preview")->assertOk();
        $file = tempnam(sys_get_temp_dir(), 'p');
        file_put_contents($file, $pdf->getContent());
        $this->mapping = $mapping;
        $words = $this->words($file);
        $this->assertWordInBox($words, 'Applicant', ['key' => 'name'] + $box('applicant_name')); // synthetic "Sample Applicant"
        $this->assertWordInBox($words, 'loan', ['key' => 'purpose'] + $box('purpose'));
        $this->assertWordInBox($words, '✓', ['key' => 'urgent'] + $box('urgent'));
        $this->assertStringNotContainsString('/AcroForm', $pdf->getContent(), 'the output is flattened: no editable fields');
    }

    public function test_text_that_cannot_fit_is_an_error_not_a_silent_cut(): void
    {
        $version = $this->template->published;
        $tight = [['key' => 'reason', 'page' => 1, 'type' => 'text', 'source' => 'form.reason', 'x' => 230, 'y' => 254, 'w' => 20, 'h' => 14, 'font_size' => 10, 'min_font_size' => 8]];
        $this->actingAs($this->designer)->postJson("/admin/pdf-template-versions/{$version->id}/preview", ['mapping' => $tight])
            ->assertStatus(422)->assertJsonPath('field', 'reason')->assertJson(fn ($j) => $j->where('message', fn ($m) => str_contains($m, 'does not fit'))->etc());

        $this->expectException(GenerationException::class);
        app(PdfRenderer::class)->render(Storage::disk('private')->path($version->working_path), $version->pages,
            [['key' => 'rows', 'page' => 1, 'type' => 'table', 'source' => 'form.items', 'x' => 50, 'y' => 400, 'w' => 400, 'h' => 40, 'table' => ['row_height' => 14, 'max_rows' => 2, 'columns' => [['path' => 'a', 'x' => 0, 'w' => 100]]]]],
            ['form' => ['items' => [['a' => 1], ['a' => 2], ['a' => 3]]]]);
    }

    public function test_hindi_is_shaped_with_an_embedded_devanagari_font(): void
    {
        $this->initiator->forceFill(['name' => 'अनीता शर्मा'])->save();
        $leave = $this->applyForLeave(['reason' => 'पारिवारिक कार्यक्रम हेतु अवकाश']);
        $this->act($leave, $this->hod);
        $fonts = (new Process(['pdffonts', $this->storedPath($this->officialDoc($leave))]))->mustRun()->getOutput();
        $this->assertMatchesRegularExpression('/FreeSerif\s+.*\byes\s+yes\b/', $fonts, 'Devanagari font embedded as a subset');
    }

    public function test_published_versions_are_immutable_and_publishing_checks_the_mapping(): void
    {
        $this->actingAs($this->designer);
        $published = $this->template->published;
        $this->put("/admin/pdf-template-versions/{$published->id}/mapping", ['mapping' => []])->assertSessionHasErrors('workflow');

        [$file] = $this->leaveForm();
        $this->post("/admin/pdf-templates/{$this->template->id}/versions", ['file' => $file])->assertSessionHasErrors('change_note');
        [$file] = $this->leaveForm();
        $this->post("/admin/pdf-templates/{$this->template->id}/versions", ['file' => $file, 'change_note' => 'Revised form'])->assertRedirect();
        $draft = $this->template->draft()->sole();

        $off = $this->mapping;
        $off[0]['x'] = 590; // off the page
        $this->put("/admin/pdf-template-versions/{$draft->id}/mapping", ['mapping' => $off])->assertSessionHasErrors('mapping');
        $bad = $this->mapping;
        $bad[0]['source'] = 'applicant.password';
        $this->put("/admin/pdf-template-versions/{$draft->id}/mapping", ['mapping' => $bad])->assertSessionHasErrors('mapping');
        $this->post("/admin/pdf-template-versions/{$draft->id}/publish")->assertSessionHas('success');

        foreach (['official_template.created', 'official_template.version_uploaded', 'official_template.mapping_saved', 'official_template.version_published', 'official_template.output_saved'] as $event) {
            $this->assertTrue(AuditEvent::query()->where('action', $event)->exists(), $event);
        }
        $this->put("/admin/pdf-templates/{$this->template->id}", ['name' => 'Leave Application', 'is_active' => false])->assertSessionHas('success');
        $this->assertTrue(AuditEvent::query()->where('action', 'official_template.deactivated')->exists());
        $leave = $this->applyForLeave();
        $this->assertSame(0, OfficialDocument::query()->where('workflow_instance_id', $leave->id)->count(), 'inactive templates produce nothing new');
    }

    public function test_values_are_formatted_as_configured(): void
    {
        $f = app(ValueFormatter::class);
        $this->assertSame('12,34,567.50', $f->format('1234567.5', ['kind' => 'amount']));
        $this->assertSame('₹ 999.00', $f->format(999, ['kind' => 'amount', 'prefix' => '₹ ']));
        $this->assertSame('02 Nov 2026', $f->format('2026-11-02', ['kind' => 'date', 'pattern' => 'd M Y']));
        $this->assertSame('ANITA', $f->format('Anita', ['kind' => 'upper']));
        $this->assertSame('Yes', $f->format(true));
        $this->assertSame('—', $f->format(null, ['empty' => '—']));
        $this->expectException(GenerationException::class);
        $f->format('soon', ['kind' => 'date']);
    }
}
