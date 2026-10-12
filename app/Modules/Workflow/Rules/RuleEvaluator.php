<?php

namespace App\Modules\Workflow\Rules;

use InvalidArgumentException;

/**
 * The rule language shared by form visibility/requirement conditions and
 * workflow transitions (docs/ARCHITECTURE.md §5.3):
 *
 *   {"all": [rule, ...]} | {"any": [rule, ...]} | {"not": rule}
 *   {"field": "path.to.value", "op": "gt", "value": 50000}
 *
 * The server is authoritative; resources/js/Workflow/rules.ts mirrors it for
 * display and both run tests/fixtures/rules.json.
 */
class RuleEvaluator
{
    public const OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'contains', 'empty', 'not_empty'];

    /** @param array<string, mixed>|null $rule */
    public function passes(?array $rule, array $context): bool
    {
        if ($rule === null || $rule === []) {
            return true;
        }
        if (isset($rule['all'])) {
            foreach ($rule['all'] as $r) {
                if (! $this->passes($r, $context)) {
                    return false;
                }
            }

            return true;
        }
        if (isset($rule['any'])) {
            foreach ($rule['any'] as $r) {
                if ($this->passes($r, $context)) {
                    return true;
                }
            }

            return false;
        }
        if (array_key_exists('not', $rule)) {
            return ! $this->passes($rule['not'], $context);
        }

        return $this->compare(data_get($context, $rule['field'] ?? ''), $rule['op'] ?? 'eq', $rule['value'] ?? null);
    }

    /**
     * Structural check used when forms and workflows are saved.
     *
     * @return list<string> problems (empty when valid)
     */
    public function problems(?array $rule, string $path = 'rule'): array
    {
        if ($rule === null || $rule === []) {
            return [];
        }
        foreach (['all', 'any'] as $group) {
            if (isset($rule[$group])) {
                if (! is_array($rule[$group]) || ! array_is_list($rule[$group])) {
                    return ["{$path}.{$group} must be a list"];
                }

                $problems = [];
                foreach ($rule[$group] as $i => $r) {
                    array_push($problems, ...(is_array($r) ? $this->problems($r, "{$path}.{$group}[{$i}]") : ["{$path}.{$group}[{$i}] must be a rule"]));
                }

                return $problems;
            }
        }
        if (array_key_exists('not', $rule)) {
            return is_array($rule['not']) ? $this->problems($rule['not'], "{$path}.not") : ["{$path}.not must be a rule"];
        }
        $problems = [];
        if (! is_string($rule['field'] ?? null) || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $rule['field'])) {
            $problems[] = "{$path}.field must be a field path";
        }
        if (! in_array($rule['op'] ?? null, self::OPERATORS, true)) {
            $problems[] = "{$path}.op must be one of ".implode(', ', self::OPERATORS);
        }
        if (in_array($rule['op'] ?? null, ['in', 'not_in'], true) && ! is_array($rule['value'] ?? null)) {
            $problems[] = "{$path}.value must be a list for {$rule['op']}";
        }

        return $problems;
    }

    /** Field paths a rule reads (for "unknown field" checks in the designer). */
    public function fields(?array $rule): array
    {
        if ($rule === null || $rule === []) {
            return [];
        }
        foreach (['all', 'any'] as $group) {
            if (isset($rule[$group])) {
                return array_values(array_unique(array_merge([], ...array_map(fn ($r) => $this->fields($r), $rule[$group]))));
            }
        }
        if (array_key_exists('not', $rule)) {
            return $this->fields($rule['not']);
        }

        return isset($rule['field']) ? [$rule['field']] : [];
    }

    private function compare(mixed $actual, string $op, mixed $expected): bool
    {
        return match ($op) {
            'empty' => $this->isEmpty($actual),
            'not_empty' => ! $this->isEmpty($actual),
            'eq' => $this->equals($actual, $expected),
            'neq' => ! $this->equals($actual, $expected),
            'gt', 'gte', 'lt', 'lte' => $this->ordered($actual, $op, $expected),
            'in' => $this->in($actual, (array) $expected),
            'not_in' => ! $this->in($actual, (array) $expected),
            'contains' => is_array($actual)
                ? $this->in($expected, $actual)
                : (is_scalar($actual) && is_scalar($expected) && $expected !== '' && str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected))),
            default => throw new InvalidArgumentException("Unknown operator [{$op}]."),
        };
    }

    private function isEmpty(mixed $v): bool
    {
        return $v === null || $v === '' || $v === [] || $v === false;
    }

    private function norm(mixed $v): mixed
    {
        if (is_bool($v) || $v === null) {
            return $v;
        }
        if (is_int($v) || is_float($v) || (is_string($v) && is_numeric(trim($v)))) {
            return (float) $v;
        }

        return is_scalar($v) ? (string) $v : $v;
    }

    private function equals(mixed $a, mixed $b): bool
    {
        if (is_array($a)) {
            return $this->in($b, $a);
        }

        return $this->norm($a) === $this->norm($b);
    }

    private function in(mixed $actual, array $set): bool
    {
        $set = array_map($this->norm(...), $set);
        foreach (is_array($actual) ? $actual : [$actual] as $v) {
            if (in_array($this->norm($v), $set, true)) {
                return true;
            }
        }

        return false;
    }

    private function ordered(mixed $a, string $op, mixed $b): bool
    {
        if ($this->isEmpty($a) && $a !== false) {
            return false;
        }
        $a = $this->norm($a);
        $b = $this->norm($b);
        if (gettype($a) !== gettype($b) || is_bool($a) || is_array($a)) {
            return false; // never compare numbers with text
        }

        return match ($op) {
            'gt' => $a > $b,
            'gte' => $a >= $b,
            'lt' => $a < $b,
            'lte' => $a <= $b,
        };
    }
}
