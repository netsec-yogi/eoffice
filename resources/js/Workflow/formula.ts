/**
 * Client mirror of App\Modules\Workflow\Rules\Formula (calculated fields,
 * shown live; the server recomputes on submit). Fixtures:
 * tests/fixtures/formulas.json.
 */
import { get } from './rules.ts';

type Token = ['num', number] | ['id', string] | ['op', string];

function tokenize(s: string): Token[] {
    const re = /\s*(?:(\d+(?:\.\d+)?)|([A-Za-z_][A-Za-z0-9_.]*)|([-+*/(),]))\s*/y;
    const tokens: Token[] = [];
    let m: RegExpExecArray | null;
    while (re.lastIndex < s.length) {
        const at = re.lastIndex;
        m = re.exec(s);
        if (!m || m[0] === '') throw new Error(`Invalid character in formula at position ${at}`);
        tokens.push(m[1] !== undefined ? ['num', Number(m[1])] : m[2] !== undefined ? ['id', m[2]] : ['op', m[3]!]);
    }
    return tokens;
}

const num = (v: unknown): number | null => (typeof v === 'number' ? v : typeof v === 'string' && v.trim() !== '' && !Number.isNaN(Number(v)) ? Number(v) : null);

export function evaluate(expression: string, context: Record<string, unknown>): number | null {
    const tokens = tokenize(expression);
    let pos = 0;
    const peek = () => tokens[pos];
    const eat = (op: string) => {
        if (peek()?.[1] !== op) throw new Error(`Expected ${op}`);
        pos++;
    };

    function expr(): number | null {
        let v = term();
        while (peek()?.[0] === 'op' && (peek()![1] === '+' || peek()![1] === '-')) {
            const op = tokens[pos++]![1];
            const r = term();
            v = v === null || r === null ? null : op === '+' ? v + r : v - r;
        }
        return v;
    }
    function term(): number | null {
        let v = factor();
        while (peek()?.[0] === 'op' && (peek()![1] === '*' || peek()![1] === '/')) {
            const op = tokens[pos++]![1];
            const r = factor();
            v = v === null || r === null ? null : op === '*' ? v * r : r === 0 ? null : v / r;
        }
        return v;
    }
    function factor(): number | null {
        const t = tokens[pos++];
        if (!t) throw new Error('Unexpected end of formula');
        if (t[0] === 'op' && t[1] === '-') {
            const v = factor();
            return v === null ? null : -v;
        }
        if (t[0] === 'op' && t[1] === '(') {
            const v = expr();
            eat(')');
            return v;
        }
        if (t[0] === 'num') return t[1];
        if (t[0] === 'id') return peek()?.[1] === '(' ? call(t[1]) : num(get(context, t[1]));
        throw new Error(`Unexpected ${t[1]}`);
    }
    function call(name: string): number | null {
        eat('(');
        if (name === 'sum' || name === 'count') {
            const path = tokens[pos++];
            if (path?.[0] !== 'id') throw new Error(`${name}() needs a field path`);
            eat(')');
            const [table, column] = path[1].split(/\.(.*)/s);
            const rows = get(context, table!);
            const list = Array.isArray(rows) ? rows : [];
            if (name === 'count') return list.length;
            return list.reduce<number>((s, r) => s + (num(r && typeof r === 'object' ? (r as Record<string, unknown>)[column ?? ''] : null) ?? 0), 0);
        }
        if (name === 'days') {
            const raw = () => {
                const t = tokens[pos++];
                if (t?.[0] !== 'id') throw new Error('days() needs field paths');
                const v = get(context, t[1]);
                return typeof v === 'string' && v !== '' ? v : null;
            };
            const from = raw();
            eat(',');
            const to = raw();
            eat(')');
            if (!from || !to) return null;
            const d = (s: string) => Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10));
            const diff = Math.round((d(to) - d(from)) / 86400000);
            return Number.isNaN(diff) ? null : diff + 1;
        }
        const args = [expr()];
        while (peek()?.[1] === ',') {
            pos++;
            args.push(expr());
        }
        eat(')');
        if (args.some((a) => a === null)) return null;
        const n = args as number[];
        if (name === 'min') return Math.min(...n);
        if (name === 'max') return Math.max(...n);
        if (name === 'round') {
            const f = 10 ** (n[1] ?? 0);
            return Math.round(n[0]! * f) / f;
        }
        throw new Error(`Unknown function ${name}()`);
    }

    const value = expr();
    if (pos < tokens.length) throw new Error(`Unexpected ${tokens[pos]![1]}`);
    return value === null || !Number.isFinite(value) ? null : Math.round(value * 1e6) / 1e6;
}
