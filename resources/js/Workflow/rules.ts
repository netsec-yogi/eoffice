/**
 * Client mirror of App\Modules\Workflow\Rules\RuleEvaluator, for showing and
 * hiding form fields while the user types. The server re-evaluates every
 * rule; never rely on this for security. Shared fixtures:
 * tests/fixtures/rules.json (npm run test:rules).
 */
export type Rule =
    | { all: Rule[] }
    | { any: Rule[] }
    | { not: Rule }
    | { field: string; op: Operator; value?: unknown };

export type Operator = 'eq' | 'neq' | 'gt' | 'gte' | 'lt' | 'lte' | 'in' | 'not_in' | 'contains' | 'empty' | 'not_empty';

export const OPERATORS: { value: Operator; label: string; needsValue: boolean }[] = [
    { value: 'eq', label: 'is', needsValue: true },
    { value: 'neq', label: 'is not', needsValue: true },
    { value: 'gt', label: '>', needsValue: true },
    { value: 'gte', label: '≥', needsValue: true },
    { value: 'lt', label: '<', needsValue: true },
    { value: 'lte', label: '≤', needsValue: true },
    { value: 'in', label: 'is one of', needsValue: true },
    { value: 'not_in', label: 'is none of', needsValue: true },
    { value: 'contains', label: 'contains', needsValue: true },
    { value: 'empty', label: 'is empty', needsValue: false },
    { value: 'not_empty', label: 'is filled in', needsValue: false },
];

export function get(context: unknown, path: string): unknown {
    return path.split('.').reduce<unknown>((v, k) => (v !== null && typeof v === 'object' ? (v as Record<string, unknown>)[k] : undefined), context);
}

const isEmpty = (v: unknown) => v === null || v === undefined || v === '' || v === false || (Array.isArray(v) && v.length === 0);

function norm(v: unknown): unknown {
    if (typeof v === 'boolean' || v === null || v === undefined) return v ?? null;
    if (typeof v === 'number') return v;
    if (typeof v === 'string' && v.trim() !== '' && !Number.isNaN(Number(v.trim()))) return Number(v.trim());
    return typeof v === 'object' ? v : String(v);
}

function inSet(actual: unknown, set: unknown[]): boolean {
    const normalised = set.map(norm);
    return (Array.isArray(actual) ? actual : [actual]).some((v) => normalised.some((s) => s === norm(v)));
}

function equals(a: unknown, b: unknown): boolean {
    return Array.isArray(a) ? inSet(b, a) : norm(a) === norm(b);
}

function ordered(a: unknown, op: string, b: unknown): boolean {
    if (isEmpty(a) && a !== false) return false;
    const x = norm(a);
    const y = norm(b);
    if (typeof x !== typeof y || typeof x === 'boolean' || typeof x === 'object') return false;
    const [p, q] = [x as number | string, y as number | string];
    return op === 'gt' ? p > q : op === 'gte' ? p >= q : op === 'lt' ? p < q : p <= q;
}

export function passes(rule: Rule | null | undefined, context: Record<string, unknown>): boolean {
    if (!rule || Object.keys(rule).length === 0) return true;
    if ('all' in rule) return rule.all.every((r) => passes(r, context));
    if ('any' in rule) return rule.any.some((r) => passes(r, context));
    if ('not' in rule) return !passes(rule.not, context);

    const actual = get(context, rule.field);
    const expected = rule.value;
    switch (rule.op) {
        case 'empty':
            return isEmpty(actual);
        case 'not_empty':
            return !isEmpty(actual);
        case 'eq':
            return equals(actual, expected);
        case 'neq':
            return !equals(actual, expected);
        case 'gt':
        case 'gte':
        case 'lt':
        case 'lte':
            return ordered(actual, rule.op, expected);
        case 'in':
            return inSet(actual, Array.isArray(expected) ? expected : [expected]);
        case 'not_in':
            return !inSet(actual, Array.isArray(expected) ? expected : [expected]);
        case 'contains':
            if (Array.isArray(actual)) return inSet(expected, actual);
            return ['string', 'number'].includes(typeof actual) && ['string', 'number'].includes(typeof expected) && expected !== '' && String(actual).toLowerCase().includes(String(expected).toLowerCase());
    }
    return false;
}
