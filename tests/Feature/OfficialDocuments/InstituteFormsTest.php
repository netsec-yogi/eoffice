<?php

namespace Tests\Feature\OfficialDocuments;

use App\Modules\OfficialDocuments\Models\OfficialDocument;
use App\Modules\OfficialDocuments\Models\PdfTemplate;
use App\Modules\OfficialDocuments\Models\PdfTemplateVersion;
use App\Modules\OfficialDocuments\Pdf\PdfChecker;
use App\Modules\OfficialDocuments\Pdf\PdfRenderer;
use App\Modules\OfficialDocuments\Pdf\ValueFormatter;
use App\Modules\OfficialDocuments\Services\DocumentData;
use Database\Seeders\FinanceWorkflowSeeder;
use Database\Seeders\InstituteFormsSeeder;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Support\RegistrarFixtures;
use Tests\TestCase;

/** The institute's own forms (resources/official-forms): import, mapping, and issue from real workflows. */
class InstituteFormsTest extends TestCase
{
    use RegistrarFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRegistrar();
        $this->seed(FinanceWorkflowSeeder::class);
        $this->seed(InstituteFormsSeeder::class);
        $this->credit($this->initiator, 'CL', 8);
        $this->credit($this->initiator, 'EL', 60);
        $this->initiator->forceFill(['name' => 'Anita Sharma', 'employee_id' => 'E1001'])->save();
        $this->cse->forceFill(['name' => 'Computer Science and Engineering'])->save(); // long names must fit the short blanks
    }

    public function test_every_supplied_form_imports_and_the_mapped_ones_are_published(): void
    {
        $this->assertSame(19, PdfTemplate::query()->count());
        $published = PdfTemplateVersion::query()->where('status', 'published')->with('template')->get()->pluck('template.key')->sort()->values()->all();
        $this->assertSame(['advance-settlement-f2', 'casual-leave', 'earned-leave', 'medical-reimbursement', 'ta-advance', 'ta-bill', 'technical-verification'], $published);
        $this->assertTrue(PdfTemplate::query()->where('key', 'medical-reimbursement')->sole()->outputs()->sole()->confidential);
        // Untouched originals: the stored file is byte-identical to the supplied one.
        $supplied = array_map(fn ($f) => hash_file('sha256', $f), glob(base_path('resources/official-forms/*.pdf')));
        foreach (PdfTemplateVersion::query()->with('original')->get() as $v) {
            $this->assertContains($v->original_sha256, $supplied, $v->original_filename);
            $this->assertSame($v->original_sha256, hash_file('sha256', Storage::disk('private')->path($v->original->path)));
        }
    }

    public function test_each_mapped_form_renders_inside_its_boxes_and_leaves_the_rest_of_the_page_unchanged(): void
    {
        foreach (PdfTemplateVersion::query()->where('status', 'published')->with('template')->get() as $v) {
            $working = Storage::disk('private')->path($v->working_path);
            $bytes = app(PdfRenderer::class)->render($working, $v->pages, $v->mapping, app(DocumentData::class)->sample($v->mapping));
            app(PdfChecker::class)->check($bytes, $v->pages);
            $out = tempnam(sys_get_temp_dir(), 'f');
            file_put_contents($out, $bytes);

            for ($page = 1; $page <= $v->page_count; $page++) {
                $boxes = array_map(fn ($f) => [$f['x'], $f['y'], $f['w'], ($f['type'] ?? '') === 'table' ? $f['table']['row_height'] * $f['table']['max_rows'] : $f['h']],
                    array_filter($v->mapping, fn ($f) => $f['page'] === $page));
                $this->assertSame(0, $this->changedOutside($working, $out, $page, $boxes), "{$v->template->key} page {$page}: content changed outside the mapped boxes");
            }
        }
    }

    public function test_leave_issues_the_form_for_its_kind_of_leave(): void
    {
        $cl = $this->start('leave', $this->initiator, ['leave_type' => 'CL', 'from_date' => '2026-11-02', 'to_date' => '2026-11-03', 'reason' => 'Family function', 'arrangement' => 'Dr. Rao takes the lab']);
        $this->act($cl, $this->hod, remarks: 'Sanctioned');
        $docs = OfficialDocument::query()->where('workflow_instance_id', $cl->id)->with('output.template')->get()->keyBy(fn ($d) => $d->output->template->key);
        $this->assertSame(['generated', 'not_generated'], [$docs['casual-leave']->status, $docs['earned-leave']->status]);

        $file = $docs['casual-leave']->file()->firstOrFail();
        $text = (new Process(['pdftotext', '-layout', Storage::disk($file->disk)->path($file->path), '-']))->mustRun()->getOutput();
        foreach (['Anita Sharma', 'E1001', 'Casual leave', '02/11/2026', '03/11/2026', 'Family function', 'Dr. Rao takes the lab', 'not a signature'] as $expected) {
            $this->assertStringContainsString($expected, preg_replace('/\s+/', ' ', $text), "casual leave form lacks \"{$expected}\"");
        }
        $this->assertStringContainsString('Application for Casual Leave', $text, 'the institute form is the background');

        $el = $this->start('leave', $this->initiator, ['leave_type' => 'EL', 'from_date' => '2026-11-09', 'to_date' => '2026-11-13', 'reason' => 'Personal work', 'leave_station' => true, 'address' => 'Lucknow']);
        $this->act($el, $this->hod);
        $docs = OfficialDocument::query()->where('workflow_instance_id', $el->id)->with('output.template')->get()->keyBy(fn ($d) => $d->output->template->key);
        $this->assertSame(['not_generated', 'generated'], [$docs['casual-leave']->status, $docs['earned-leave']->status]);
    }

    public function test_amounts_in_words_and_combined_fields(): void
    {
        $this->assertSame('One Lakh Twenty-Five Thousand Four Hundred Thirty Rupees and Fifty Paise Only', ValueFormatter::words(125430.5));
        $this->assertSame('One Crore Two Rupees Only', ValueFormatter::words(10000002));
        $this->assertSame('Zero Rupees Only', ValueFormatter::words(0));
        $ctx = ['applicant' => ['name' => 'A. Sharma', 'designation' => null, 'department' => 'CSE'], 'form' => ['x' => '']];
        $this->assertSame('A. Sharma, CSE', PdfRenderer::interpolate('{{ applicant.name }}, {{ applicant.designation }}, {{ applicant.department }}', $ctx));
        $this->assertSame('Ravi', PdfRenderer::interpolate('Ravi ({{ form.x }})', $ctx));
    }

    /** Pixels that differ between two renderings outside the given boxes (pt), at 36 dpi. */
    private function changedOutside(string $a, string $b, int $page, array $boxes): int
    {
        $raster = function (string $pdf) use ($page): \GdImage {
            $out = tempnam(sys_get_temp_dir(), 'r');
            (new Process(['pdftoppm', '-png', '-r', '36', '-f', (string) $page, '-l', (string) $page, '-singlefile', $pdf, $out]))->mustRun();

            return imagecreatefrompng($out.'.png');
        };
        [$ia, $ib] = [$raster($a), $raster($b)];
        $scale = 0.5;
        $changed = 0;
        for ($x = 0; $x < imagesx($ia); $x++) {
            for ($y = 0; $y < imagesy($ia); $y++) {
                foreach ($boxes as [$bx, $by, $bw, $bh]) {
                    if ($x >= ($bx - 2) * $scale && $x <= ($bx + $bw + 2) * $scale && $y >= ($by - 2) * $scale && $y <= ($by + $bh + 2) * $scale) {
                        continue 2;
                    }
                }
                $changed += (int) (imagecolorat($ia, $x, $y) !== imagecolorat($ib, $x, $y));
            }
        }

        return $changed;
    }
}
