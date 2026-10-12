<?php

namespace App\Modules\Workflow\Forms;

use App\Modules\Workflow\Rules\Formula;
use App\Modules\Workflow\Rules\RuleEvaluator;

/**
 * Checks the structure of a form schema when an administrator saves it, so a
 * published form can always be rendered and validated.
 */
class FormSchemaValidator
{
    private const KEY = '/^[a-z][a-z0-9_]{0,63}$/';

    public function __construct(private RuleEvaluator $rules, private Formula $formula) {}

    /** @return list<string> */
    public function problems(array $schema): array
    {
        $problems = [];
        $sections = $schema['sections'] ?? null;
        if (! is_array($sections) || ! array_is_list($sections) || $sections === []) {
            return ['The form needs at least one section.'];
        }

        $keys = [];
        foreach ($sections as $s => $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (isset($field['key'])) {
                    $keys[$field['key']] = $field['type'] ?? null;
                }
            }
        }
        $known = fn (string $path) => isset($keys[explode('.', $path)[0]]) || str_starts_with($path, 'initiator.');

        $seenSections = [];
        $seenFields = [];
        foreach ($sections as $s => $section) {
            $where = 'Section '.($s + 1);
            if (! is_string($section['key'] ?? null) || ! preg_match(self::KEY, $section['key'])) {
                $problems[] = "{$where}: key must be lower_snake_case.";
            } elseif (isset($seenSections[$section['key']])) {
                $problems[] = "{$where}: duplicate section key '{$section['key']}'.";
            }
            $seenSections[$section['key'] ?? ''] = true;
            if (trim((string) ($section['title'] ?? '')) === '') {
                $problems[] = "{$where}: title is required.";
            }
            $problems = [...$problems, ...$this->ruleProblems($section['visible_if'] ?? null, "{$where} visibility", $known)];

            foreach ($section['fields'] ?? [] as $f => $field) {
                $label = $field['key'] ?? ('#'.($f + 1));
                $where = "Field '{$label}'";
                if (! is_string($field['key'] ?? null) || ! preg_match(self::KEY, $field['key'])) {
                    $problems[] = "{$where}: key must be lower_snake_case (letters, digits, underscores).";

                    continue;
                }
                if (isset($seenFields[$field['key']])) {
                    $problems[] = "{$where}: the key is used twice.";
                }
                $seenFields[$field['key']] = true;
                if (in_array($field['key'], ['initiator', 'vars', 'instance'], true)) {
                    $problems[] = "{$where}: '{$field['key']}' is reserved.";
                }
                $problems = [...$problems, ...$this->fieldProblems($field, $where, $known, $keys)];
            }
        }

        return $problems;
    }

    private function fieldProblems(array $field, string $where, callable $known, array $keys, bool $inTable = false): array
    {
        $problems = [];
        $type = $field['type'] ?? null;
        $allowed = $inTable ? FieldTypes::TABLE_COLUMNS : FieldTypes::ALL;
        if (! in_array($type, $allowed, true)) {
            return ["{$where}: type '{$type}' is not allowed here."];
        }
        if (trim((string) ($field['label'] ?? '')) === '' && $type !== 'readonly') {
            $problems[] = "{$where}: label is required.";
        }
        if (! empty($field['source'])) {
            if (! in_array($field['source'], app(OptionSources::class)->keys(), true)) {
                $problems[] = "{$where}: unknown option source '{$field['source']}'.";
            }
        } elseif (in_array($type, FieldTypes::WITH_OPTIONS, true)) {
            $options = $field['options'] ?? [];
            if (! is_array($options) || $options === []) {
                $problems[] = "{$where}: add at least one option.";
            } else {
                $values = array_map(fn ($o) => is_array($o) ? ($o['value'] ?? null) : null, $options);
                if (in_array(null, $values, true) || in_array('', $values, true)) {
                    $problems[] = "{$where}: every option needs a value.";
                } elseif (count($values) !== count(array_unique($values))) {
                    $problems[] = "{$where}: option values must be unique.";
                }
            }
        }
        if ($type === 'table') {
            $columns = $field['columns'] ?? [];
            if (! is_array($columns) || $columns === []) {
                $problems[] = "{$where}: a table needs columns.";
            }
            $colKeys = [];
            foreach ((array) $columns as $c => $column) {
                if (! is_string($column['key'] ?? null) || ! preg_match(self::KEY, $column['key']) || isset($colKeys[$column['key']])) {
                    $problems[] = "{$where}: column ".($c + 1).' needs a unique lower_snake_case key.';

                    continue;
                }
                $colKeys[$column['key']] = true;
                $problems = [...$problems, ...$this->fieldProblems($column, "{$where} column '{$column['key']}'", $known, $keys, true)];
            }
        }
        if ($type === 'calculated') {
            $formula = (string) ($field['formula'] ?? '');
            if ($formula === '') {
                $problems[] = "{$where}: a calculated field needs a formula.";
            } else {
                foreach ($this->formula->problems($formula) as $p) {
                    $problems[] = "{$where}: formula: {$p}.";
                }
                foreach ($this->formula->fields($formula) as $path) {
                    if (! $known($path)) {
                        $problems[] = "{$where}: formula refers to unknown field '{$path}'.";
                    }
                }
            }
        }
        foreach (['min', 'max', 'min_length', 'max_length', 'max_files', 'max_kb', 'min_rows', 'max_rows'] as $n) {
            if (isset($field[$n]) && $field[$n] !== '' && ! is_numeric($field[$n]) && ! in_array($type, ['date', 'datetime', 'time'], true)) {
                $problems[] = "{$where}: {$n} must be a number.";
            }
        }
        if (isset($field['pattern']) && $field['pattern'] !== '' && @preg_match('/'.str_replace('/', '\/', $field['pattern']).'/u', '') === false) {
            $problems[] = "{$where}: pattern is not a valid regular expression.";
        }

        return [
            ...$problems,
            ...$this->ruleProblems($field['visible_if'] ?? null, "{$where} visibility", $known),
            ...$this->ruleProblems($field['required_if'] ?? null, "{$where} requirement", $known),
        ];
    }

    private function ruleProblems(?array $rule, string $where, callable $known): array
    {
        $problems = array_map(fn ($p) => "{$where}: {$p}.", $this->rules->problems($rule));
        foreach ($this->rules->fields($rule) as $path) {
            if (! $known($path)) {
                $problems[] = "{$where}: refers to unknown field '{$path}'.";
            }
        }

        return $problems;
    }
}
