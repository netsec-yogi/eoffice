// Runs the shared rule and formula fixtures against the TypeScript mirrors
// (PHP runs the same files in tests/Unit/RuleFixturesTest.php).
// node --experimental-strip-types is needed for .ts imports (Node >= 22.6).
import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import { evaluate } from './formula.ts';
import { passes } from './rules.ts';

const load = (f) => JSON.parse(fs.readFileSync(new URL(`../../../tests/fixtures/${f}`, import.meta.url)));

for (const c of load('rules.json')) test(`rule: ${c.name}`, () => assert.equal(passes(c.rule, c.context), c.expected));
for (const c of load('formulas.json')) test(`formula: ${c.name}`, () => assert.equal(evaluate(c.formula, c.context), c.expected));
