<?php

namespace App\Modules\OfficialDocuments\Services;

use App\Modules\OfficialDocuments\Pdf\ValueFormatter;
use App\Modules\Workflow\Rules\RuleEvaluator;

/** Structural checks on a field mapping before it is saved and again before publishing. */
class MappingValidator
{
    public const TYPES = ['text', 'multiline', 'checkbox', 'table'];

    public function __construct(private RuleEvaluator $rules) {}

    /** @return list<string> */
    public function problems(array $mapping, array $pages): array
    {
        $p = [];
        $keys = [];
        foreach (array_values($mapping) as $i => $f) {
            $name = ($f['label'] ?? null) ?: ($f['key'] ?? 'Field '.($i + 1));
            $key = (string) ($f['key'] ?? '');
            if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) {
                $p[] = "{$name}: the key must be lower-case letters, digits and underscores.";
            } elseif (isset($keys[$key])) {
                $p[] = "{$name}: duplicate key \"{$key}\".";
            }
            $keys[$key] = true;
            if (! in_array($f['type'] ?? null, self::TYPES, true)) {
                $p[] = "{$name}: unknown type.";
            }
            $page = (int) ($f['page'] ?? 0);
            if (! isset($pages[$page - 1])) {
                $p[] = "{$name}: page {$page} does not exist in this template.";

                continue;
            }
            $source = (string) ($f['source'] ?? '');
            if ($source === 'literal') {
                if (trim((string) ($f['text'] ?? '')) === '') {
                    $p[] = "{$name}: fixed text is empty.";
                }
                preg_match_all('/\{\{\s*([\w.\-]+)\s*\}\}/', (string) ($f['text'] ?? ''), $m);
                foreach ($m[1] as $placeholder) {
                    if (! $this->validSource($placeholder)) {
                        $p[] = "{$name}: \"{{ {$placeholder} }}\" is not a known data source.";
                    }
                }
            } elseif (! $this->validSource($source)) {
                $p[] = "{$name}: \"{$source}\" is not a known data source.";
            }
            foreach (['x', 'y', 'w', 'h'] as $c) {
                if (! is_numeric($f[$c] ?? null)) {
                    $p[] = "{$name}: position {$c} missing.";

                    continue 2;
                }
            }
            [$x, $y, $w, $h] = [(float) $f['x'], (float) $f['y'], (float) $f['w'], (float) $f['h']];
            $pg = $pages[$page - 1];
            $tableH = ($f['type'] ?? '') === 'table' ? (float) ($f['table']['row_height'] ?? 14) * max(1, (int) ($f['table']['max_rows'] ?? 1)) : $h;
            if ($w < 4 || $h < 4 || $x < 0 || $y < 0 || $x + $w > $pg['width'] + 0.5 || $y + $tableH > $pg['height'] + 0.5) {
                $p[] = "{$name}: the box must lie on the page (at least 4 pt square).";
            }
            $size = (float) ($f['font_size'] ?? 10);
            if ($size < 5 || $size > 36 || (float) ($f['min_font_size'] ?? 7) < 4 || (float) ($f['min_font_size'] ?? 7) > $size) {
                $p[] = "{$name}: font size must be 5–36 pt, the minimum between 4 pt and the size.";
            }
            if (! in_array($f['align'] ?? 'L', ['L', 'C', 'R'], true) || ! in_array($f['overflow'] ?? 'shrink', ['shrink', 'error'], true) || ! in_array($f['font'] ?? 'sans', ['sans', 'serif', 'mono'], true)) {
                $p[] = "{$name}: alignment, font or overflow setting is not valid.";
            }
            if (! in_array($f['format']['kind'] ?? 'auto', ValueFormatter::KINDS, true)) {
                $p[] = "{$name}: unknown format.";
            }
            if (! empty($f['condition'])) {
                foreach ($this->rules->problems($f['condition'], "{$name} condition") as $problem) {
                    $p[] = $problem;
                }
            }
            if (($f['type'] ?? '') === 'table') {
                $cols = $f['table']['columns'] ?? [];
                if ($cols === [] || (int) ($f['table']['max_rows'] ?? 0) < 1) {
                    $p[] = "{$name}: a table needs columns and the number of rows the form has room for.";
                }
                foreach ($cols as $c) {
                    if (! preg_match('/^([\w-]+|#)$/', (string) ($c['path'] ?? '')) || (float) ($c['x'] ?? -1) < 0 || (float) ($c['w'] ?? 0) < 4 || (float) $c['x'] + (float) $c['w'] > $w + 0.5) {
                        $p[] = "{$name}: each column needs a field name and must lie inside the table box.";
                        break;
                    }
                }
            }
        }

        return $p;
    }

    public function validSource(string $source): bool
    {
        $parts = explode('.', $source);

        return match ($parts[0]) {
            'applicant' => count($parts) === 2 && in_array($parts[1], DocumentData::APPLICANT, true),
            'workflow' => count($parts) === 2 && in_array($parts[1], DocumentData::WORKFLOW, true),
            'document' => count($parts) === 2 && in_array($parts[1], DocumentData::DOCUMENT, true),
            'institute' => count($parts) === 2 && array_key_exists($parts[1], config('official_documents.institute')),
            'approval' => count($parts) === 3 && preg_match('/^[\w-]{1,64}$/', $parts[1]) && in_array($parts[2], DocumentData::APPROVAL, true),
            'approvals' => count($parts) === 1,
            'form', 'form_label' => count($parts) >= 2 && preg_match('/^[\w-]{1,64}$/', $parts[1]) === 1,
            default => false,
        };
    }
}
