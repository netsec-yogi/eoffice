<?php

namespace App\Modules\OfficialDocuments\Pdf;

use Mpdf\Mpdf;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Reads a supplied PDF without changing it (DOC-001/002):
 *  - writes a normalised working copy (classic cross-reference table, no
 *    object streams, owner-password restrictions removed) because the free
 *    FPDI parser cannot read compressed cross-reference streams;
 *  - page sizes in points, as the renderer will see them;
 *  - interactive (AcroForm) fields with their rectangles, top-left origin.
 */
class TemplateInspector
{
    /** @return array{pages: list<array{width: float, height: float}>, form_fields: list<array>} */
    public function inspect(string $original, string $working): array
    {
        if (file_get_contents($original, length: 5) !== '%PDF-') {
            throw new TemplateException('The file is not a PDF.');
        }
        $qpdf = new Process([config('official_documents.tools.qpdf'), '--object-streams=disable', '--decrypt', '--', $original, $working]);
        $qpdf->setTimeout(60)->run();
        // 0 = ok, 3 = ok with warnings (common in real-world forms).
        if (! in_array($qpdf->getExitCode(), [0, 3], true) || ! is_file($working)) {
            throw new TemplateException(str_contains($qpdf->getErrorOutput(), 'password') ? 'The PDF is password-protected. Supply an unprotected copy.' : 'The PDF could not be read: '.trim(strtok($qpdf->getErrorOutput(), "\n")));
        }

        $pages = $this->pages($working);
        if (count($pages) > config('official_documents.max_pages')) {
            throw new TemplateException('The PDF has '.count($pages).' pages; the limit is '.config('official_documents.max_pages').'.');
        }

        return ['pages' => $pages, 'form_fields' => $this->fields($working, $pages)];
    }

    /** @return list<array{width: float, height: float}> */
    public function pages(string $working): array
    {
        try {
            $pdf = new Mpdf(['tempDir' => self::tempDir()]);
            $count = $pdf->setSourceFile($working);
            $pages = [];
            for ($i = 1; $i <= $count; $i++) {
                $size = $pdf->getTemplateSize($pdf->importPage($i));
                $pages[] = ['width' => round($size['width'] * 72 / 25.4, 2), 'height' => round($size['height'] * 72 / 25.4, 2)];
            }

            return $pages;
        } catch (Throwable $e) {
            throw new TemplateException('The PDF could not be imported: '.$e->getMessage());
        }
    }

    /** Widget annotations of interactive fields, converted to top-left points. */
    private function fields(string $working, array $pages): array
    {
        $p = new Process([config('official_documents.tools.qpdf'), '--json', '--json-key=acroform', '--json-key=pages', '--json-key=qpdf', '--', $working]);
        $p->setTimeout(60)->run();
        $json = json_decode($p->getOutput(), true);
        if (! is_array($json) || empty($json['acroform']['fields'])) {
            return [];
        }
        $objects = $json['qpdf'][1] ?? [];
        $obj = fn (?string $ref) => $ref ? ($objects['obj:'.$ref]['value'] ?? []) : [];
        $out = [];
        foreach ($json['acroform']['fields'] as $f) {
            $annot = $obj($f['annotation']['object'] ?? null);
            $page = (int) ($f['pageposfrom1'] ?? 0);
            $rect = $annot['/Rect'] ?? null;
            if (! $page || ! is_array($rect) || count($rect) !== 4 || ! isset($pages[$page - 1])) {
                continue;
            }
            $pageObj = $obj($json['pages'][$page - 1]['object'] ?? null);
            $box = $pageObj['/MediaBox'] ?? [0, 0, $pages[$page - 1]['width'], $pages[$page - 1]['height']];
            [$x1, $y1, $x2, $y2] = [min($rect[0], $rect[2]), min($rect[1], $rect[3]), max($rect[0], $rect[2]), max($rect[1], $rect[3])];
            $out[] = [
                'name' => (string) $f['fullname'],
                'type' => match ($f['fieldtype'] ?? '') {
                    '/Tx' => 'text', '/Btn' => ($f['isradiobutton'] ?? false) ? 'radio' : 'checkbox', '/Ch' => 'choice', '/Sig' => 'signature', default => 'other'
                },
                'multiline' => (bool) (((int) ($f['fieldflags'] ?? 0)) & 4096),
                'page' => $page,
                'x' => round($x1 - (float) $box[0], 2),
                'y' => round($pages[$page - 1]['height'] - ($y2 - (float) $box[1]), 2),
                'w' => round($x2 - $x1, 2),
                'h' => round($y2 - $y1, 2),
                'options' => array_values(array_map('strval', (array) ($f['choices'] ?? []))),
                'value' => $f['appearancestate'] ?? null,
            ];
        }

        return $out;
    }

    /** Page image for the mapper and previews. */
    public function renderPage(string $pdf, int $page, int $dpi = 96): string
    {
        $dir = self::tempDir();
        $prefix = $dir.'/page-'.bin2hex(random_bytes(6));
        $p = new Process([config('official_documents.tools.pdftoppm'), '-png', '-r', (string) $dpi, '-f', (string) $page, '-l', (string) $page, '-singlefile', $pdf, $prefix]);
        $p->setTimeout(60)->run();
        if (! $p->isSuccessful() || ! is_file($prefix.'.png')) {
            throw new TemplateException('The page could not be rendered.');
        }
        $png = file_get_contents($prefix.'.png');
        @unlink($prefix.'.png');

        return $png;
    }

    public static function tempDir(): string
    {
        $dir = config('official_documents.temp_dir');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir;
    }
}
