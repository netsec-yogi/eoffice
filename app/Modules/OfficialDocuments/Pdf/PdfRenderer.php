<?php

namespace App\Modules\OfficialDocuments\Pdf;

use App\Modules\Workflow\Rules\RuleEvaluator;
use Mpdf\Mpdf;
use Throwable;

/**
 * Fills an official template (DOC-002, DOC-005). Every page of the
 * normalised original is imported unchanged as the background; mapped
 * values are drawn on top inside their boxes. A value that does not fit at
 * the smallest allowed size is an error, never silently cut. Interactive
 * fields are not used as fields: their rectangles are boxes like any other,
 * so every template renders the same way, Devanagari included (the result
 * is flattened, not editable).
 */
class PdfRenderer
{
    private const PT = 25.4 / 72; // points -> mm

    private Mpdf $pdf;

    public function __construct(private ValueFormatter $formatter, private RuleEvaluator $rules) {}

    /**
     * @param  array  $pages  [{width, height}] in points
     * @param  list<array>  $mapping
     * @param  array{title?: string, subject?: string}  $meta
     */
    public function render(string $working, array $pages, array $mapping, array $context, array $meta = []): string
    {
        try {
            $this->pdf = new Mpdf(['mode' => 'utf-8', 'tempDir' => TemplateInspector::tempDir(), 'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0, 'margin_header' => 0, 'margin_footer' => 0]);
            $this->pdf->SetAutoPageBreak(false);
            $this->pdf->SetCreator((string) config('official_documents.institute.name'));
            $this->pdf->SetTitle($meta['title'] ?? '');
            $this->pdf->SetSubject($meta['subject'] ?? '');
            $count = $this->pdf->setSourceFile($working);
        } catch (Throwable $e) {
            throw new GenerationException('render_failed', 'Template could not be opened: '.$e->getMessage());
        }

        for ($page = 1; $page <= $count; $page++) {
            $tpl = $this->pdf->importPage($page);
            $size = $this->pdf->getTemplateSize($tpl);
            $this->pdf->AddPageByArray(['orientation' => $size['width'] > $size['height'] ? 'L' : 'P', 'sheet-size' => [$size['width'], $size['height']],
                'margin-left' => 0, 'margin-right' => 0, 'margin-top' => 0, 'margin-bottom' => 0, 'margin-header' => 0, 'margin-footer' => 0]);
            $this->pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height']);

            foreach ($mapping as $field) {
                if ((int) ($field['page'] ?? 0) === $page && $this->rules->passes($field['condition'] ?? null, $context)) {
                    $this->field($field, $context);
                }
            }
        }

        try {
            return $this->pdf->Output('', 'S');
        } catch (Throwable $e) {
            throw new GenerationException('render_failed', $e->getMessage());
        }
    }

    private function field(array $f, array $context): void
    {
        $label = $f['label'] ?? $f['key'];
        $raw = ($f['source'] ?? '') === 'literal' ? self::interpolate((string) ($f['text'] ?? ''), $context) : data_get($context, $f['source'] ?? '');
        if (($f['required'] ?? false) && ValueFormatter::isEmpty($raw)) {
            throw new GenerationException('missing_data', "\"{$label}\" is required but {$f['source']} has no value.", $f['key']);
        }

        try {
            match ($f['type'] ?? 'text') {
                'checkbox' => $this->checkbox($f, $raw),
                'table' => $this->table($f, is_array($raw) ? array_values($raw) : []),
                'multiline' => $this->text($f, $this->formatter->format($raw, $f['format'] ?? []), true),
                default => $this->text($f, $this->formatter->format($raw, $f['format'] ?? []), false),
            };
        } catch (GenerationException $e) {
            throw $e->field ? $e : new GenerationException($e->reason, "\"{$label}\": ".$e->getMessage(), $f['key']);
        }
    }

    /**
     * Fixed text with {{ path }} placeholders, for blanks that combine several
     * values ("Name, Designation, Department"). Empty values leave nothing.
     */
    public static function interpolate(string $text, array $context): string
    {
        $out = preg_replace_callback('/\{\{\s*([\w.\-]+)\s*\}\}/', function ($m) use ($context) {
            $v = data_get($context, $m[1]);

            return is_scalar($v) ? (string) $v : '';
        }, $text);

        // Tidy separators left by empty placeholders: "A, , C" -> "A, C"; "A ()" -> "A".
        return trim(preg_replace(['/\(\s*\)/', '/(,\s*)+,/', '/^[\s,]+|[\s,]+$/'], ['', ',', ''], $out));
    }

    private function checkbox(array $f, mixed $value): void
    {
        $match = $f['checkbox']['match'] ?? null;
        $checked = $match !== null && $match !== ''
            ? (is_array($value) ? in_array((string) $match, array_map('strval', $value), true) : (string) $value === (string) $match)
            : in_array($value, [true, 1, '1', 'yes', 'true', 'on'], true);
        if (! $checked) {
            return;
        }
        $size = min(14, max(6, (float) $f['h'] * 0.9));
        $this->line($f['checkbox']['mark'] ?? '✓', (float) $f['x'], (float) $f['y'], (float) $f['w'], (float) $f['h'], config('official_documents.fonts.sans'), $size, 'C', true);
    }

    private function table(array $f, array $rows): void
    {
        $t = $f['table'] ?? [];
        $max = (int) ($t['max_rows'] ?? 0);
        if ($max && count($rows) > $max) {
            throw new GenerationException('too_many_rows', count($rows)." rows; the form has room for {$max}.");
        }
        $rowH = (float) ($t['row_height'] ?? 14);
        foreach ($rows as $i => $row) {
            foreach ($t['columns'] ?? [] as $c) {
                $cell = ['key' => $f['key'].'.'.$i.'.'.($c['path'] ?? ''), 'x' => (float) $f['x'] + (float) $c['x'], 'y' => (float) $f['y'] + $i * $rowH, 'w' => (float) $c['w'], 'h' => $rowH,
                    'align' => $c['align'] ?? 'L', 'font' => $f['font'] ?? 'sans', 'font_size' => $f['font_size'] ?? 9, 'min_font_size' => $f['min_font_size'] ?? 6, 'overflow' => $f['overflow'] ?? 'shrink'];
                $value = ($c['path'] ?? '') === '#' ? $i + 1 : data_get($row, $c['path'] ?? '');
                $this->text($cell, $this->formatter->format($value, $c['format'] ?? []), false);
            }
        }
    }

    private function text(array $f, string $text, bool $multiline): void
    {
        $text = trim(preg_replace('/[^\P{C}\n]/u', '', $text)); // drop control characters, keep line breaks
        if ($text === '') {
            return;
        }
        $font = preg_match('/[\x{0900}-\x{097F}]/u', $text) ? config('official_documents.fonts.devanagari') : (config('official_documents.fonts.'.($f['font'] ?? 'sans')) ?? config('official_documents.fonts.sans'));
        [$x, $y, $w, $h] = [(float) $f['x'], (float) $f['y'], (float) $f['w'], (float) $f['h']];
        $size = (float) ($f['font_size'] ?? 10);
        $min = ($f['overflow'] ?? 'shrink') === 'shrink' ? min($size, (float) ($f['min_font_size'] ?? 7)) : $size;
        $leading = (float) ($f['line_height'] ?? 1.2);
        $inner = ($w - 2) * self::PT; // 1pt inner padding each side

        for (; $size >= $min - 0.001; $size -= 0.5) {
            $this->pdf->SetFont($font, '', $size);
            $lines = $multiline ? $this->wrap($text, $inner) : [str_replace("\n", ' ', $text)];
            $fits = ! $multiline
                ? $this->pdf->GetStringWidth($lines[0]) <= $inner && $size <= $h
                : $lines !== null && count($lines) * $size * $leading <= $h + 0.01;
            if ($fits) {
                $lineH = $size * $leading;
                $top = $multiline ? $y : $y + max(0, ($h - $lineH) / 2);
                foreach ($lines as $i => $line) {
                    $this->line($line, $x + 1, $top + $i * $lineH, $w - 2, $lineH, $font, $size, $f['align'] ?? 'L');
                }

                return;
            }
        }

        throw new GenerationException('overflow', 'the value ('.mb_strlen($text).' characters) does not fit its box even at '.$min.' pt. Enlarge the box, allow multiple lines or shorten the value.');
    }

    /** Word wrap to a width in mm; null when a single word cannot fit. */
    private function wrap(string $text, float $width): ?array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) as $paragraph) {
            $current = '';
            foreach (preg_split('/\s+/u', trim($paragraph)) as $word) {
                $candidate = $current === '' ? $word : $current.' '.$word;
                if ($this->pdf->GetStringWidth($candidate) <= $width) {
                    $current = $candidate;

                    continue;
                }
                if ($current !== '') {
                    $lines[] = $current;
                }
                if ($this->pdf->GetStringWidth($word) > $width) {
                    return null;
                }
                $current = $word;
            }
            $lines[] = $current;
        }

        return $lines;
    }

    /** One line through mPDF's HTML path, which applies OpenType shaping (Devanagari). */
    private function line(string $text, float $x, float $y, float $w, float $h, string $font, float $size, string $align, bool $symbol = false): void
    {
        $alignCss = ['L' => 'left', 'C' => 'center', 'R' => 'right'][$align] ?? 'left';
        $html = '<div style="font-family:'.$font.';font-size:'.$size.'pt;line-height:'.($h / $size).';white-space:nowrap;text-align:'.$alignCss.';color:#000">'
            .htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</div>';
        $this->pdf->WriteFixedPosHTML($html, $x * self::PT, $y * self::PT, $w * self::PT, $h * self::PT, 'visible');
    }
}
