<?php

namespace App\Modules\Workflow\Rules;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Arithmetic for calculated form fields. No code execution: a small
 * recursive-descent parser over numbers, field paths, + - * / ( ) and the
 * functions sum(table.column), count(table), min(..), max(..), round(x, n)
 * and days(from, to) (inclusive calendar days). Missing inputs give null.
 * Mirrored in resources/js/Workflow/formula.ts; both run
 * tests/fixtures/formulas.json.
 */
class Formula
{
    private array $tokens = [];

    private int $pos = 0;

    private array $context = [];

    public function evaluate(string $expression, array $context): ?float
    {
        $this->tokens = $this->tokenize($expression);
        $this->pos = 0;
        $this->context = $context;
        $value = $this->expression();
        if ($this->pos < count($this->tokens)) {
            throw new InvalidArgumentException('Unexpected '.$this->tokens[$this->pos][1]);
        }

        return $value === null || is_nan($value) || is_infinite($value) ? null : round($value, 6);
    }

    /** @return list<string> problems */
    public function problems(string $expression): array
    {
        try {
            $this->evaluate($expression, []);

            return [];
        } catch (InvalidArgumentException $e) {
            return [$e->getMessage()];
        }
    }

    /** Field paths the expression reads. */
    public function fields(string $expression): array
    {
        $fields = [];
        $tokens = $this->tokenize($expression);
        foreach ($tokens as $i => [$type, $value]) {
            if ($type === 'id' && ($tokens[$i + 1][1] ?? null) !== '(') {
                $fields[] = $value;
            }
        }

        return array_values(array_unique($fields));
    }

    private function tokenize(string $s): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($s);
        while ($offset < $length) {
            if (! preg_match('/\G\s*(?:(\d+(?:\.\d+)?)|([A-Za-z_][A-Za-z0-9_.]*)|([-+*\/(),]))\s*/', $s, $m, 0, $offset) || $m[0] === '') {
                throw new InvalidArgumentException('Invalid character in formula at position '.$offset);
            }
            $offset += strlen($m[0]);
            $tokens[] = match (true) {
                ($m[1] ?? '') !== '' => ['num', (float) $m[1]],
                ($m[2] ?? '') !== '' => ['id', $m[2]],
                default => ['op', $m[3]],
            };
        }

        return $tokens;
    }

    private function peek(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function eat(string $op): void
    {
        if (($this->peek()[1] ?? null) !== $op) {
            throw new InvalidArgumentException("Expected {$op}");
        }
        $this->pos++;
    }

    private function expression(): ?float
    {
        $value = $this->term();
        while (in_array($this->peek()[1] ?? null, ['+', '-'], true)) {
            $op = $this->tokens[$this->pos++][1];
            $right = $this->term();
            $value = $value === null || $right === null ? null : ($op === '+' ? $value + $right : $value - $right);
        }

        return $value;
    }

    private function term(): ?float
    {
        $value = $this->factor();
        while (in_array($this->peek()[1] ?? null, ['*', '/'], true)) {
            $op = $this->tokens[$this->pos++][1];
            $right = $this->factor();
            $value = match (true) {
                $value === null || $right === null => null,
                $op === '*' => $value * $right,
                $right == 0.0 => null,
                default => $value / $right,
            };
        }

        return $value;
    }

    private function factor(): ?float
    {
        $token = $this->peek() ?? throw new InvalidArgumentException('Unexpected end of formula');
        $this->pos++;

        if ($token[1] === '-' && $token[0] === 'op') {
            $v = $this->factor();

            return $v === null ? null : -$v;
        }
        if ($token[1] === '(' && $token[0] === 'op') {
            $v = $this->expression();
            $this->eat(')');

            return $v;
        }
        if ($token[0] === 'num') {
            return $token[1];
        }
        if ($token[0] === 'id') {
            if (($this->peek()[1] ?? null) === '(') {
                return $this->call($token[1]);
            }

            return $this->number(data_get($this->context, $token[1]));
        }

        throw new InvalidArgumentException('Unexpected '.$token[1]);
    }

    private function call(string $name): ?float
    {
        $this->eat('(');
        if (in_array($name, ['sum', 'count'], true)) {
            $path = $this->tokens[$this->pos++] ?? null;
            if (($path[0] ?? null) !== 'id') {
                throw new InvalidArgumentException("{$name}() needs a field path");
            }
            $this->eat(')');
            [$table, $column] = array_pad(explode('.', $path[1], 2), 2, null);
            $rows = data_get($this->context, $table);
            $rows = is_array($rows) ? array_values($rows) : [];
            if ($name === 'count') {
                return (float) count($rows);
            }

            return array_sum(array_map(fn ($r) => $this->number(is_array($r) ? ($r[$column] ?? null) : null) ?? 0.0, $rows));
        }

        if ($name === 'days') {
            $from = $this->rawArgument();
            $this->eat(',');
            $to = $this->rawArgument();
            $this->eat(')');
            try {
                return ($from && $to) ? (float) (CarbonImmutable::parse($from)->startOfDay()->diffInDays(CarbonImmutable::parse($to)->startOfDay(), false) + 1) : null;
            } catch (\Throwable) {
                return null;
            }
        }

        $args = [$this->expression()];
        while (($this->peek()[1] ?? null) === ',') {
            $this->pos++;
            $args[] = $this->expression();
        }
        $this->eat(')');
        if (in_array(null, $args, true)) {
            return null;
        }

        return match ($name) {
            'min' => min($args),
            'max' => max($args),
            'round' => round($args[0], (int) ($args[1] ?? 0)),
            default => throw new InvalidArgumentException("Unknown function {$name}()"),
        };
    }

    private function rawArgument(): ?string
    {
        $token = $this->tokens[$this->pos++] ?? null;
        if (($token[0] ?? null) !== 'id') {
            throw new InvalidArgumentException('days() needs field paths');
        }
        $v = data_get($this->context, $token[1]);

        return is_string($v) && $v !== '' ? $v : null;
    }

    private function number(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }
}
