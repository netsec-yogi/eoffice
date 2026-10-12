<?php

namespace App\Modules\Workflow\Forms;

use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Rules\Formula;
use App\Modules\Workflow\Rules\RuleEvaluator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validates and normalises submitted form data against a schema (FORM-003):
 * hidden fields are dropped, required_if rules are applied on the server,
 * calculated fields are recomputed (never trusted from the client), files
 * must be the submitter's own uploads.
 *
 * At a workflow step ($atNode) only fields whose `editable_at` lists that
 * step may change; everything else keeps its previous value.
 */
class FormDataValidator
{
    private ?User $optionsUser = null;

    public function __construct(private RuleEvaluator $rules, private Formula $formula) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $existing  previously stored data (resubmission or step edits)
     * @return array<string, mixed> clean data
     *
     * @throws ValidationException
     */
    public function validate(array $schema, array $input, User $actor, ?string $atNode = null, array $existing = [], ?User $initiator = null): array
    {
        $initiator ??= $actor;
        $this->optionsUser = $initiator;
        // At submission a field is the initiator's unless it is reserved for
        // later steps (editable_at without "start"); remarks are never theirs.
        $editable = fn (array $f) => $atNode === null
            ? ! in_array($f['type'], FieldTypes::NOT_INPUT, true)
                && (empty($f['editable_at']) ? $f['type'] !== 'remark' : in_array('start', $f['editable_at'], true))
            : in_array($atNode, $f['editable_at'] ?? [], true);

        // Values the rules see: editable fields from the input, the rest as stored.
        $merged = $existing;
        foreach ($this->fields($schema) as $field) {
            if ($editable($field)) {
                $merged[$field['key']] = $input[$field['key']] ?? null;
            }
        }
        $context = $this->context($merged, $initiator);

        $rules = [];
        $attributes = [];
        $visible = [];
        foreach ($schema['sections'] as $section) {
            $sectionVisible = $this->rules->passes($section['visible_if'] ?? null, $context);
            foreach ($section['fields'] ?? [] as $field) {
                if (! $sectionVisible || ! $this->rules->passes($field['visible_if'] ?? null, $context)) {
                    continue;
                }
                $visible[$field['key']] = $field;
                if (! $editable($field)) {
                    continue;
                }
                $required = ! empty($field['required']) || (! empty($field['required_if']) && $this->rules->passes($field['required_if'], $context));
                foreach ($this->rulesFor($field, $required, $actor, $existing) as $key => $r) {
                    $rules[$key] = $r;
                }
                $attributes[$field['key']] = $field['label'] ?? $field['key'];
                foreach ($field['columns'] ?? [] as $column) {
                    $attributes["{$field['key']}.*.{$column['key']}"] = $column['label'] ?? $column['key'];
                }
            }
        }

        $validator = Validator::make($merged, $rules, [], $attributes);
        $validator->validate();

        // Build the clean result: only visible fields, typed.
        $clean = [];
        foreach ($visible as $key => $field) {
            if ($field['type'] === 'calculated' || $field['type'] === 'readonly' || $field['type'] === 'signature') {
                continue;
            }
            $clean[$key] = $this->cast($field, $merged[$key] ?? null);
        }
        foreach ($visible as $key => $field) {
            if ($field['type'] === 'calculated') {
                $clean[$key] = $this->formula->evaluate($field['formula'], $this->context($clean, $initiator));
            }
        }

        return $clean;
    }

    /** @return list<array<string, mixed>> every field of every section */
    public function fields(array $schema): array
    {
        return array_merge([], ...array_map(fn ($s) => $s['fields'] ?? [], $schema['sections'] ?? []));
    }

    /** Fields carrying document references, with their values in $data. */
    public function documentIds(array $schema, array $data): array
    {
        $ids = [];
        foreach ($this->fields($schema) as $field) {
            if ($field['type'] === 'file' && is_string($data[$field['key']] ?? null)) {
                $ids[] = $data[$field['key']];
            } elseif ($field['type'] === 'files' && is_array($data[$field['key']] ?? null)) {
                array_push($ids, ...array_filter($data[$field['key']], 'is_string'));
            }
        }

        return array_values(array_unique($ids));
    }

    public function context(array $data, User $initiator): array
    {
        return $data + ['initiator' => [
            'id' => $initiator->id,
            'category' => $initiator->category,
            'department' => $initiator->department_id ? Department::query()->whereKey($initiator->department_id)->value('code') : null,
        ]];
    }

    /** @return array<string, list<mixed>> */
    private function rulesFor(array $f, bool $required, User $actor, array $existing): array
    {
        $key = $f['key'];
        $base = [$required ? 'required' : 'nullable'];
        $num = fn (string $n) => isset($f[$n]) && $f[$n] !== '' ? ["{$n}:".$f[$n]] : [];
        $len = array_merge(
            isset($f['min_length']) && $f['min_length'] !== '' ? ['min:'.(int) $f['min_length']] : [],
            ['max:'.(int) ($f['max_length'] ?? ($f['type'] === 'textarea' || $f['type'] === 'remark' ? 5000 : 255))],
        );
        $pattern = ! empty($f['pattern']) ? ['regex:/'.str_replace('/', '\/', $f['pattern']).'/u'] : [];
        $options = array_map(fn ($o) => (string) $o['value'], ! empty($f['source']) ? app(OptionSources::class)->options($f['source'], $this->optionsUser ?? $actor) : ($f['options'] ?? []));

        return match ($f['type']) {
            'text', 'textarea', 'remark' => [$key => [...$base, 'string', ...$len, ...$pattern]],
            'email' => [$key => [...$base, 'email:rfc', 'max:255']],
            'tel' => [$key => [...$base, 'string', 'regex:/^\+?[0-9 ()-]{6,20}$/']],
            'number' => [$key => [...$base, 'integer', ...$num('min'), ...$num('max')]],
            'decimal' => [$key => [...$base, 'numeric', ...$num('min'), ...$num('max')]],
            'date' => [$key => [...$base, 'date_format:Y-m-d', ...$this->dateBounds($f)]],
            'datetime' => [$key => [...$base, 'date', ...$this->dateBounds($f)]],
            'time' => [$key => [...$base, 'date_format:H:i']],
            'select', 'radio' => [$key => [...$base, Rule::in($options)]],
            'multiselect' => [$key => [...$base, 'array', ...($required ? ['min:1'] : [])], "{$key}.*" => [Rule::in($options)]],
            'checkbox' => [$key => $required ? ['accepted'] : ['nullable', 'boolean']],
            'department' => [$key => [...$base, 'integer', Rule::exists('departments', 'id')->where('is_active', true)]],
            'user', 'student', 'vendor' => [$key => [...$base, 'integer', Rule::exists('users', 'id')->where('is_active', true)->where('directory_disabled', false)
                ->when(FieldTypes::PEOPLE[$f['type']] ?? ($f['category'] ?? null), fn ($r, $c) => $r->where('category', $c))]],
            'file' => [$key => [...$base, 'string', $this->ownDocument($actor, $f, $existing[$key] ?? null)]],
            'files' => [
                $key => [...$base, 'array', ...($required ? ['min:1'] : []), 'max:'.(int) ($f['max_files'] ?? 10)],
                "{$key}.*" => ['string', $this->ownDocument($actor, $f, $existing[$key] ?? [])],
            ],
            'table' => $this->tableRules($f, $required),
            default => [],
        };
    }

    private function tableRules(array $f, bool $required): array
    {
        $key = $f['key'];
        $rules = [$key => [$required ? 'required' : 'nullable', 'array', 'min:'.max($required ? 1 : 0, (int) ($f['min_rows'] ?? 0)), 'max:'.(int) ($f['max_rows'] ?? 50)]];
        foreach ($f['columns'] ?? [] as $column) {
            foreach ($this->rulesFor($column, ! empty($column['required']), new User, []) as $ck => $cr) {
                $rules["{$key}.*.{$ck}"] = $cr;
            }
        }

        return $rules;
    }

    private function dateBounds(array $f): array
    {
        $out = [];
        foreach (['min' => 'after_or_equal', 'max' => 'before_or_equal'] as $bound => $rule) {
            $v = $f[$bound] ?? null;
            if (is_string($v) && $v !== '') {
                // A literal date, "today", or another field of the form.
                $out[] = "{$rule}:".(preg_match('/^\d{4}-\d{2}-\d{2}$|^today$/', $v) ? $v : $v);
            }
        }

        return $out;
    }

    /** The document must be the actor's own unattached upload (or already part of this record). */
    private function ownDocument(User $actor, array $field, mixed $previous): \Closure
    {
        $previous = array_filter((array) $previous, 'is_string');
        $accept = array_map('strtolower', $field['accept'] ?? []);

        return function (string $attribute, mixed $value, \Closure $fail) use ($actor, $previous, $accept, $field) {
            if (in_array($value, $previous, true)) {
                return;
            }
            $document = is_string($value) ? Document::query()->where('uuid', $value)->first() : null;
            if (! $document || $document->uploaded_by !== $actor->id || $document->attachable_id !== null) {
                $fail('The uploaded file was not found. Please upload it again.');

                return;
            }
            $extension = strtolower(pathinfo($document->original_name, PATHINFO_EXTENSION));
            if ($accept !== [] && ! in_array($extension, $accept, true)) {
                $fail('Allowed file types: '.implode(', ', $accept).'.');
            }
            if (! empty($field['max_kb']) && $document->size > (int) $field['max_kb'] * 1024) {
                $fail("The file must not be larger than {$field['max_kb']} KB.");
            }
        };
    }

    private function cast(array $f, mixed $v): mixed
    {
        if ($v === null || $v === '') {
            return $f['type'] === 'multiselect' || $f['type'] === 'files' || $f['type'] === 'table' ? [] : ($f['type'] === 'checkbox' ? false : null);
        }

        return match ($f['type']) {
            'number', 'department', 'user', 'student', 'vendor' => (int) $v,
            'decimal' => (float) $v,
            'checkbox' => filter_var($v, FILTER_VALIDATE_BOOLEAN),
            'multiselect', 'files' => array_values((array) $v),
            'table' => array_values(array_map(fn ($row) => collect($f['columns'])->mapWithKeys(fn ($c) => [$c['key'] => $this->cast($c, $row[$c['key']] ?? null)])->all(), (array) $v)),
            default => is_string($v) ? trim($v) : $v,
        };
    }
}
