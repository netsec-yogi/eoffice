<?php

namespace App\Modules\OfficialDocuments\Pdf;

use Mpdf\Mpdf;
use Symfony\Component\Process\Process;
use Throwable;

/** A generated document is accepted only if it is a sound PDF with the template's pages and sizes. */
class PdfChecker
{
    public function check(string $bytes, array $pages): void
    {
        if (! str_starts_with($bytes, '%PDF-') || strlen($bytes) < 200) {
            throw new GenerationException('validation_failed', 'Output is not a PDF.');
        }
        $path = tempnam(TemplateInspector::tempDir(), 'chk');
        file_put_contents($path, $bytes);
        try {
            $qpdf = new Process([config('official_documents.tools.qpdf'), '--check', '--', $path]);
            $qpdf->setTimeout(60)->run();
            if (! in_array($qpdf->getExitCode(), [0, 3], true)) {
                throw new GenerationException('validation_failed', 'qpdf --check: '.trim(strtok($qpdf->getOutput().$qpdf->getErrorOutput(), "\n")));
            }
            try {
                $pdf = new Mpdf(['tempDir' => TemplateInspector::tempDir()]);
                $count = $pdf->setSourceFile($path);
            } catch (Throwable $e) {
                throw new GenerationException('validation_failed', 'Output cannot be re-opened: '.$e->getMessage());
            }
            if ($count !== count($pages)) {
                throw new GenerationException('validation_failed', "Output has {$count} pages; the template has ".count($pages).'.');
            }
            foreach ($pages as $i => $expected) {
                $size = $pdf->getTemplateSize($pdf->importPage($i + 1));
                if (abs($size['width'] * 72 / 25.4 - $expected['width']) > 1 || abs($size['height'] * 72 / 25.4 - $expected['height']) > 1) {
                    throw new GenerationException('validation_failed', 'Page '.($i + 1).' size differs from the template.');
                }
            }
        } finally {
            @unlink($path);
        }
    }
}
