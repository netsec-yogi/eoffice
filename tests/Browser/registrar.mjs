// Phase 5 scenario through the real UI (make fresh demo first):
// leave application and sanction; passport NOC issued and verified publicly;
// circular drafted, approved, read and acknowledged.
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
const nextMonday = (() => {
    const d = new Date();
    d.setDate(d.getDate() + ((8 - d.getDay()) % 7 || 7) + 14);
    return d.toISOString().slice(0, 10);
})();
const plus = (iso, n) => new Date(Date.parse(iso) + n * 864e5).toISOString().slice(0, 10);

// 1. Leave
let ctx = await session('asharma');
await go('/leave');
await shot('rg-01-my-leave');
await go('/requests/new/leave');
await (await field('Kind of leave')).select('CL');
await setDate('From', nextMonday);
await setDate('To', plus(nextMonday, 1));
await (await field('Reason')).type('Family function');
await clickText('Submit');
await page.waitForFunction(() => /^\/requests\/\d+$/.test(location.pathname));
const leavePath = new URL(page.url()).pathname;
await shot('rg-02-leave-submitted');
check((await page.content()).includes('Sanctioning authority'), 'leave waiting for the sanctioning authority');
await ctx.close();

ctx = await session('pkumar');
await actWhenReady(leavePath, 'Sanction');
await ctx.close();

ctx = await session('asharma');
for (let i = 0; i < 20 && !(await page.content()).includes('approved'); i++) {
    await go('/leave');
    await new Promise((r) => setTimeout(r, 300));
}
const leavePage = await page.content();
check(leavePage.includes('6 <span') || leavePage.includes('>6<'), 'casual leave balance down from 8 to 6');
await shot('rg-03-balance');

// 2. Passport NOC
await go('/requests/new/noc-passport');
await (await field('For')).select('renewal of passport');
await clickText('Submit');
await page.waitForFunction(() => /^\/requests\/\d+$/.test(location.pathname));
const nocPath = new URL(page.url()).pathname;
await ctx.close();
ctx = await session('pkumar');
await actWhenReady(nocPath, 'Recommend');
await actWhenReady(nocPath, 'Approve and sign');
await ctx.close();
ctx = await session('asharma');
for (let i = 0; i < 20; i++) {
    await go('/certificates');
    if ((await page.content()).includes('NOC/PP/')) break;
    await new Promise((r) => setTimeout(r, 500));
}
await shot('rg-04-certificates');
const verifyUrl = await page.$$eval('a', (as) => as.find((a) => a.textContent.trim() === 'Verify')?.href);
check(!!verifyUrl, 'certificate issued with a verification link');
await ctx.close();
ctx = await session(null);
await page.goto(verifyUrl, { waitUntil: 'networkidle0' });
await shot('rg-05-verify-public');
check((await page.content()).includes('Genuine and valid'), 'public verification works without signing in');
await ctx.close();

// 3. Circular
ctx = await session('rverma');
await go('/circulars/new');
await (await field('Title')).type('Campus network maintenance');
await (await field('Text')).type('The campus network will be unavailable on Saturday from 8 am to 12 noon for maintenance.');
await page.click('input[type=checkbox][value=student]');
const ackBox = await page.$$eval('label', (ls) => ls.findIndex((l) => l.textContent.includes('Recipients must acknowledge')));
await (await page.$$('label'))[ackBox].click();
await clickText('Save draft');
await page.waitForFunction(() => /^\/circulars\/\d+$/.test(location.pathname));
const circPath = new URL(page.url()).pathname;
await clickText('Send for approval');
await ctx.close();
ctx = await session('pkumar');
await go('/tasks');
await clickText('Circular: Campus network maintenance', 'a');
await clickText('Approve for issue');
await ctx.close();
ctx = await session('asharma');
for (let i = 0; i < 20; i++) {
    await go('/circulars');
    if ((await page.content()).includes('Campus network maintenance')) break;
    await new Promise((r) => setTimeout(r, 500));
}
await shot('rg-06-circulars-inbox');
await go(circPath);
await clickText('Acknowledge');
check((await page.content()).includes('You acknowledged this'), 'circular acknowledged');
await shot('rg-07-circular');
await ctx.close();

ctx = await session('rverma');
await go(circPath);
await shot('rg-08-circular-report');
await go('/rti');
await shot('rg-09-rti');
await go('/claims?all=1');
await shot('rg-10-claims');
await go('/leave/admin');
await shot('rg-11-leave-admin');
await ctx.close();

await browser.close();
if (errors.length) {
    console.error('\nErrors:\n' + errors.join('\n'));
    process.exit(1);
}
console.log('\nRegistrar scenario passed.');
