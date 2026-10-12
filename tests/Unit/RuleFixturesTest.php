<?php

namespace Tests\Unit;

use App\Modules\Workflow\Rules\Formula;
use App\Modules\Workflow\Rules\RuleEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Shared fixtures: the TypeScript mirror runs the same files (npm run test:rules). */
class RuleFixturesTest extends TestCase
{
    public static function rules(): iterable
    {
        foreach (json_decode(file_get_contents(__DIR__.'/../fixtures/rules.json'), true) as $c) {
            yield $c['name'] => [$c['rule'], $c['context'], $c['expected']];
        }
    }

    public static function formulas(): iterable
    {
        foreach (json_decode(file_get_contents(__DIR__.'/../fixtures/formulas.json'), true) as $c) {
            yield $c['name'] => [$c['formula'], $c['context'], $c['expected']];
        }
    }

    #[DataProvider('rules')]
    public function test_rule(?array $rule, array $context, bool $expected): void
    {
        $this->assertSame($expected, (new RuleEvaluator)->passes($rule, $context));
    }

    #[DataProvider('formulas')]
    public function test_formula(string $formula, array $context, int|float|null $expected): void
    {
        $actual = (new Formula)->evaluate($formula, $context);
        $this->assertSame($expected === null ? null : (float) $expected, $actual);
    }

    public function test_rule_structure_problems_are_reported(): void
    {
        $e = new RuleEvaluator;
        $this->assertSame([], $e->problems(['all' => [['field' => 'a', 'op' => 'eq', 'value' => 1]]]));
        $this->assertNotEmpty($e->problems(['field' => 'a', 'op' => 'like']));
        $this->assertNotEmpty($e->problems(['field' => 'a; drop', 'op' => 'eq']));
        $this->assertNotEmpty($e->problems(['field' => 'a', 'op' => 'in', 'value' => 'x']));
    }

    public function test_formulas_reject_anything_but_arithmetic(): void
    {
        $f = new Formula;
        $this->assertNotEmpty($f->problems('system("ls")'));
        $this->assertNotEmpty($f->problems('a + '));
        $this->assertNotEmpty($f->problems('a $ b'));
        $this->assertSame(['fare', 'da', 'items.amount'], $f->fields('fare + da * 2 + sum(items.amount)'));
    }
}
