// Phase 6 scenario through the real UI (make fresh demo first):
// indent -> budget commitment -> quotations and comparative statement ->
// committee and approval -> PO -> vendor portal -> receipt -> vendor bill ->
// verification, sanction, payment -> payment advice in the portal.
import puppeteer from 'puppeteer-core';
import fs from 'node:fs';

const base = process.argv[2] ?? 'http://localhost:8100';
const out = process.argv[3] ?? 'storage/shots';
const executablePath = process.env.CHROME ?? ['/usr/bin/google-chrome', '/usr/bin/chromium-browser'].find((p) => fs.existsSync(p));
fs.mkdirSync(out, { recursive: true });
const browser = await puppeteer.launch({ executablePath, headless: true, args: ['--no-sandbox'] });
const errors = [];
let page;

async function session(username) {
    const ctx = await browser.createBrowserContext();
    page = await ctx.newPage();
    page.on('pageerror', (e) => errors.push(`${username}: ${e.message}`));
    page.on('console', (m) => m.type() === 'error' && errors.push(`${username}: ${m.text()}`));
    page.on('response', (r) => r.status() >= 400 && errors.push(`${username}: HTTP ${r.status()} ${r.url()}`));
    await page.setViewport({ width: 1440, height: 900 });
    if (username) {
        await page.goto(`${base}/login`, { waitUntil: 'networkidle0' });
        await page.type('input[autocomplete=username]', username);
        await page.type('input[type=password]', 'Dev-Password-2026');
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('button[type=submit]')]);
    }
    return ctx;
}
const shot = (name) => page.screenshot({ path: `${out}/${name}.png`, fullPage: true });
const go = (path) => page.goto(base + path, { waitUntil: 'networkidle0' });
async function clickText(text, selector = 'button, a') {
    const h = await page.waitForFunction((t, sel) => [...document.querySelectorAll(sel)].find((e) => e.textContent.trim() === t && e.offsetParent !== null), { timeout: 8000 }, text, selector);
    await Promise.all([page.waitForNetworkIdle({ idleTime: 400 }), h.asElement().click()]);
}
async function field(label) {
    return page.waitForFunction((l) => {
        const lab = [...document.querySelectorAll('label')].find((x) => x.textContent.trim().replace(/\s*\*$/, '') === l);
        return lab && (document.getElementById(lab.htmlFor) ?? lab.parentElement.querySelector('input, textarea, select'));
    }, { timeout: 5000 }, label);
}
async function setDate(label, value) {
    const el = await field(label);
    await el.evaluate((e, v) => {
        Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(e, v);
        e.dispatchEvent(new Event('input', { bubbles: true }));
    }, value);
}
// Queued workflow steps finish shortly after a decision: reload until the action appears.
// Until the task exists the person may not even see the request (403):
// that refusal is correct and is not counted as an error while waiting.
async function actWhenReady(path, text) {
    const mark = errors.length;
    for (let i = 0; i < 20; i++) {
        await go(path);
        const ready = await page.$$eval('button', (b, t) => b.some((x) => x.textContent.trim() === t), text);
        if (ready) {
            const kept = errors.splice(mark).filter((e) => !e.endsWith(`HTTP 403 ${base}${path}`) && !e.includes('status of 403 (Forbidden)'));
            errors.push(...kept);
            return clickText(text);
        }
        await new Promise((r) => setTimeout(r, 500));
    }
    throw new Error(`"${text}" never became available on ${path}`);
}
const check = (cond, msg) => (cond ? console.log('✓', msg) : (errors.push(`ASSERTION: ${msg}`), console.error('✗', msg)));

async function pickUser(query) {
    const input = await page.waitForSelector('input[role=combobox]');
    await input.type(query);
    const option = await page.waitForSelector('li[role=option]', { timeout: 5000 });
    await option.click();
}
async function choose(label, matcher) {
    const el = await field(label);
    const value = await el.$$eval('option', (os, m) => os.find((o) => o.value && o.textContent.includes(m))?.value, matcher);
    if (!value) throw new Error(`no option "${matcher}" for ${label}`);
    await el.select(value);
}
async function cell(label, value) {
    const el = await page.waitForSelector(`[aria-label="${label}"]`);
    if ((await el.evaluate((e) => e.tagName)) === 'SELECT') return el.select(value);
    await el.click({ clickCount: 3 });
    await el.type(String(value));
}
async function typeIn(label, value) {
    const el = await field(label);
    await el.click({ clickCount: 3 });
    await el.type(String(value));
}
async function submitted() {
    await clickText('Submit');
    await page.waitForFunction(() => /^\/(requests\/\d+|vendor)$/.test(location.pathname), { timeout: 10000 });
    return new URL(page.url()).pathname;
}
async function waitFor(path, text) {
    for (let i = 0; i < 30; i++) {
        await go(path);
        if ((await page.content()).includes(text)) return true;
        await new Promise((r) => setTimeout(r, 500));
    }
    return false;
}
const today = new Date().toISOString().slice(0, 10);
const pdf = `${out}/synthetic-invoice.pdf`;
fs.writeFileSync(pdf, '%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n');

// 1. Anita raises an indent; the DEMO rules prescribe quotations and a committee.
let ctx = await session('asharma');
await go('/requests/new/purchase-indent');
await typeIn('What is to be purchased', 'Digital storage oscilloscopes');
await typeIn('Justification', 'Signals lab: two benches without a working oscilloscope.');
await choose('Budget head', 'CSE-EQP');
await clickText('Add row');
await cell('Item, row 1', 'Oscilloscope, 100 MHz, 2 channel');
await cell('Qty, row 1', 2);
await cell('Unit, row 1', 'nos');
await cell('Estimated unit price (₹), row 1', 30000);
await shot('fn-01-indent');
const indentPath = await submitted();
await shot('fn-02-indent-submitted');
await ctx.close();

ctx = await session('pkumar');
await actWhenReady(indentPath, 'Approve indent');
await ctx.close();

// 2. Finance concurs and commits funds; the purchase officer sources.
ctx = await session('rverma');
await actWhenReady(indentPath, 'Funds available');
check(await waitFor('/purchases', 'Digital storage oscilloscopes'), 'purchase case opened');
const casePath = new URL(await page.$$eval('a', (as) => as.find((a) => a.textContent.includes('Digital storage oscilloscopes')).href)).pathname;
check(await waitFor(casePath, 'Sourcing'), 'case is with the purchase officer');
check((await page.content()).includes('Demo band B'), 'method decided by the published (demo) rule set');
await (await field('Available on GeM?')).select('not_available');
await clickText('Save');
for (const [vendor, amount] of [['ACME', '57500'], ['ZENITH', '59000'], ['ORBIT', '61250']]) {
    await choose('Vendor', `(${vendor})`);
    await typeIn('Amount incl. taxes (₹)', amount);
    await clickText('Add quotation');
}
await clickText('Select');
await clickText('Confirm selection');
await shot('fn-03-comparative');
check(!(await page.content()).includes('Before the evaluation can be completed'), 'evaluation complete under the rules');
await actWhenReady(indentPath, 'Evaluation complete');
await actWhenReady(indentPath, 'Approve'); // purchase committee member
await ctx.close();

ctx = await session('pkumar');
await actWhenReady(indentPath, 'Approve'); // purchase committee member
await actWhenReady(indentPath, 'Approve purchase'); // Registrar (value below the example limit)
await ctx.close();

// 3. The vendor sees only their order.
ctx = await session('v-acme');
check(await waitFor('/vendor', 'PO/'), 'purchase order visible in the vendor portal');
await shot('fn-04-vendor-po');
const before = errors.length;
await go('/purchases');
check(errors.length > before && errors.slice(before).every((e) => e.includes('403')), 'vendor refused internal pages (403)');
errors.splice(before);
await ctx.close();

// 4. Delivery and inspection.
ctx = await session('asharma');
await go('/requests/new/goods-receipt');
await choose('Purchase order', 'PO/');
await setDate('Received on', today);
await clickText('Add row');
await cell('Item (as on the order), row 1', 'Oscilloscope, 100 MHz, 2 channel');
await cell('Received, row 1', 2);
await cell('Accepted, row 1', 2);
await pickUser('pkumar');
const grnPath = await submitted();
await ctx.close();
ctx = await session('pkumar');
await actWhenReady(grnPath, 'Accept goods');
await ctx.close();

// 5. The vendor bills; Finance verifies, sanctions and pays.
ctx = await session('v-acme');
for (let i = 0; i < 20; i++) {
    await go('/requests/new/vendor-invoice');
    if ((await page.content()).includes('PO/')) break;
    await new Promise((r) => setTimeout(r, 500));
}
await choose('Purchase order', 'PO/');
await typeIn('Invoice number', 'ACME/26-27/0042');
await setDate('Invoice date', today);
await typeIn('Invoice amount incl. taxes (₹)', '57500');
await (await page.$('input[type=file]')).uploadFile(pdf);
await page.waitForNetworkIdle({ idleTime: 500 });
await shot('fn-05-invoice');
check((await submitted()) === '/vendor', 'vendor returned to the portal after submitting');
await ctx.close();

ctx = await session('rverma');
check(await waitFor('/tasks', 'ACME/26-27/0042'), 'bill reached Finance');
const billPath = new URL(await page.$$eval('a', (as) => as.find((a) => a.textContent.includes('ACME/26-27/0042')).href)).pathname;
await go(billPath);
await typeIn('Deductions (TDS, penalties) ₹', '1150');
await clickText('Verified');
await actWhenReady(billPath, 'Sanction payment');
// Wait for the payment task itself (the field is shown read-only before then).
for (let i = 0; i < 20 && !(await page.$$eval('button', (b) => b.some((x) => x.textContent.trim() === 'Paid'))); i++) {
    await new Promise((r) => setTimeout(r, 500));
    await go(billPath);
}
await typeIn('Payment reference (UTR / cheque)', 'UTR-SYNTH-0001');
await setDate('Paid on', today);
await clickText('Paid');
await ctx.close();

ctx = await session('v-acme');
check(await waitFor('/vendor', 'UTR-SYNTH-0001'), 'vendor sees the payment and reference');
check((await page.content()).includes('Payment advice'), 'payment advice available to the vendor');
check(!(await page.content()).includes('Verification notes'), 'no internal notes in the portal');
await shot('fn-08-vendor-paid');
await ctx.close();

// 6. Finance: the order value moved from committed to spent.
ctx = await session('rverma');
check(await waitFor('/budget', '57,500.00'), 'budget page');
const spentCell = await page.$$eval('tr', (rs) => rs.find((r) => r.textContent.includes('CSE-EQP'))?.children[4]?.textContent.trim());
check(spentCell?.includes('57,500.00'), `CSE-EQP shows the payment as spent (${spentCell})`);
check(!(await page.content()).includes('-0.00'), 'no negative zero amounts');
await shot('fn-06-budget');
await go('/purchases/rules');
await shot('fn-07-rules');
check((await page.content()).includes('demonstration values'), 'demo rules flagged as not institutional');
await ctx.close();

await browser.close();
if (errors.length) {
    console.error(errors.join('\n'));
    process.exit(1);
}
console.log('finance scenario passed');
