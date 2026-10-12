<?php

namespace Tests\Support;

use App\Modules\OfficialDocuments\Models\OfficialDocument;
use App\Modules\OfficialDocuments\Models\PdfTemplate;
use App\Modules\OfficialDocuments\Models\WorkflowDocumentOutput;
use App\Modules\OfficialDocuments\Pdf\TemplateInspector;
use App\Modules\OfficialDocuments\Services\TemplateService;
use App\Modules\OfficialDocuments\Support\SyntheticTemplates;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/** The leave workflow with a synthetic official leave form attached. */
trait OfficialDocumentFixtures
{
    use RegistrarFixtures;

    protected PdfTemplate $template;

    protected WorkflowDocumentOutput $output;

    protected array $mapping;

    protected function setUpOfficialDocuments(array $output = []): void
    {
        $this->setUpRegistrar();
        $this->credit($this->initiator, 'CL', 8);
        $this->initiator->forceFill(['name' => 'Anita Sharma'])->save();
        [$file, $this->mapping] = $this->leaveForm();
        $service = app(TemplateService::class);
        $this->template = $service->create(['key' => 'leave-application', 'name' => 'Leave Application'], $file, $this->designer);
        $version = $this->template->versions()->sole();
        $service->saveMapping($version, $this->mapping, $this->designer);
        $service->publish($version->fresh(), $this->designer);
        $this->output = $service->saveOutput($this->template, $output + [
            'workflow_definition_id' => WorkflowDefinition::query()->where('key', 'leave')->value('id'),
            'required_steps' => ['sanction'], 'download_groups' => ['applicant', 'records'],
        ], $this->designer);
    }

    /** The synthetic form as an upload, saved with compressed cross-reference streams (as many real PDFs are). */
    protected function leaveForm(string $title = 'Leave Application.pdf'): array
    {
        $dir = TemplateInspector::tempDir();
        $plain = "{$dir}/fixture-".bin2hex(random_bytes(4)).'.pdf';
        $mapping = SyntheticTemplates::leave($plain);
        (new Process(['qpdf', '--object-streams=generate', $plain, $plain.'.x.pdf']))->mustRun();
        rename($plain.'.x.pdf', $plain);

        return [new UploadedFile($plain, $title, 'application/pdf', null, true), $mapping];
    }

    protected function applyForLeave(array $data = []): WorkflowInstance
    {
        return $this->start('leave', $this->initiator, $data + ['leave_type' => 'CL', 'from_date' => '2026-11-02', 'to_date' => '2026-11-03', 'reason' => 'Family function at home town']);
    }

    protected function officialDoc(WorkflowInstance $i): OfficialDocument
    {
        return OfficialDocument::query()->where('workflow_instance_id', $i->id)->whereNotIn('status', ['superseded'])->sole();
    }

    protected function storedPath(OfficialDocument $doc): string
    {
        $file = $doc->file()->firstOrFail();

        return Storage::disk($file->disk)->path($file->path);
    }

    /** Words with their boxes in points (top-left origin), from poppler. @return list<array{text: string, page: int, x1: float, y1: float, x2: float, y2: float}> */
    protected function words(string $pdf): array
    {
        $p = new Process(['pdftotext', '-bbox', $pdf, '-']);
        $p->mustRun();
        $words = [];
        $page = 0;
        foreach (explode("\n", $p->getOutput()) as $line) {
            if (str_contains($line, '<page ')) {
                $page++;
            }
            if (preg_match('/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">(.*)<\/word>/', $line, $m)) {
                $words[] = ['text' => html_entity_decode($m[5]), 'page' => $page, 'x1' => (float) $m[1], 'y1' => (float) $m[2], 'x2' => (float) $m[3], 'y2' => (float) $m[4]];
            }
        }

        return $words;
    }

    protected function assertWordInBox(array $words, string $text, array $field): void
    {
        $hits = array_filter($words, fn ($w) => $w['text'] === $text && $w['page'] === (int) $field['page']);
        $this->assertNotEmpty($hits, "\"{$text}\" not found on page {$field['page']}");
        $inside = array_filter($hits, fn ($w) => $w['x1'] >= $field['x'] - 0.5 && $w['x2'] <= $field['x'] + $field['w'] + 0.5 && $w['y1'] >= $field['y'] - 0.5 && $w['y2'] <= $field['y'] + $field['h'] + 0.5);
        $this->assertNotEmpty($inside, "\"{$text}\" is not inside the box of {$field['key']}: ".json_encode(array_values($hits)));
    }

    protected function field(string $key): array
    {
        return collect($this->mapping)->firstWhere('key', $key);
    }
}
