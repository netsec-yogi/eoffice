// Official PDF scenario through the real UI (make fresh demo first): the
// institute's casual leave form in the mapper and preview; leave applied, document waiting; sanctioned; applicant
// downloads; an officer outside the download groups is refused; monitor.
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

const nextWeekday = (() => {
    const d = new Date(Date.now() + 49 * 864e5); // clear of the registrar scenario's leave
    while ([0, 6].includes(d.getDay())) d.setDate(d.getDate() + 1);
    return d.toISOString().slice(0, 10);
})();
async function waitFor(path, text) {
    for (let i = 0; i < 30; i++) {
        await go(path);
        if ((await page.content()).includes(text)) return true;
        await new Promise((r) => setTimeout(r, 500));
    }
    return false;
}
// Fetch inside the signed-in page: status and type of a download link.
const probe = (url) => page.evaluate(async (u) => { const r = await fetch(u, { credentials: 'same-origin' }); return { status: r.status, type: r.headers.get('content-type'), size: (await r.arrayBuffer()).byteLength }; }, url);

// 1. The template administrator sees the form, its mapping and a sample preview.
let ctx = await session('rverma');
await go('/admin/pdf-templates');
await shot('od-01-templates');
check((await page.content()).includes('Application for Casual Leave'), 'institute casual leave form registered');
await clickText('Application for Casual Leave / RH / Station Leave / Special Casual Leave', 'a');
await shot('od-02-template');
await clickText('Mapping');
await page.waitForSelector('img[alt="Page 1 of the form"]');
await page.waitForFunction(() => document.querySelector('img[alt="Page 1 of the form"]').complete);
await shot('od-03-mapper');
await clickText('Preview with sample data');
await page.waitForFunction(() => document.querySelector('img[alt="Page 1 of the form"]').src.startsWith('data:image/png'), { timeout: 15000 });
await shot('od-04-preview');
check(true, 'populated preview rendered from synthetic data');
await ctx.close();

// 2. Anita applies for leave: the form is promised but not available.
ctx = await session('asharma');
await go('/requests/new/leave');
await (await field('Kind of leave')).select('CL');
await setDate('From', nextWeekday);
await setDate('To', nextWeekday);
await (await field('Reason')).type('पारिवारिक कार्यक्रम - family function');
await clickText('Submit');
await page.waitForFunction(() => /^\/requests\/\d+$/.test(location.pathname));
const leavePath = new URL(page.url()).pathname;
check(await waitFor(leavePath, 'Waiting for final approval'), 'request page: document waiting for final approval');
await shot('od-05-waiting');
const before = await page.$$eval('a', (as) => as.filter((a) => a.textContent.includes('Download')).length);
check(before === 0, 'no download before approval');
await ctx.close();

// 3. The sanctioning authority approves.
ctx = await session('pkumar');
await actWhenReady(leavePath, 'Sanction');
await ctx.close();

// 4. Anita downloads the official form.
ctx = await session('asharma');
// The casual leave form specifically (other scenarios may have issued documents too).
const leaveLink = () => page.$$eval('tr', (rows) => rows.find((r) => r.textContent.includes('Application for Casual Leave') && r.textContent.includes('Download PDF'))?.querySelector('a[href*="/download"]')?.href);
let link = null;
for (let i = 0; i < 30 && !link; i++) {
    await go('/official-documents');
    link = await leaveLink();
    if (!link) await new Promise((r) => setTimeout(r, 500));
}
check(!!link, 'casual leave form ready after final approval');
await shot('od-06-my-documents');
const got = await probe(link);
check(got.status === 200 && got.type === 'application/pdf' && got.size > 1000, `applicant downloads the PDF (${got.status}, ${got.type}, ${got.size} bytes)`);
await go('/');
await shot('od-07-dashboard');
check((await page.content()).includes('Official documents'), 'dashboard lists official documents');
await ctx.close();

// 5. Someone not entitled gets nothing, even with the link.
ctx = await session('pkumar');
const mark = errors.length;
const denied = await probe(link);
errors.splice(mark); // the refusal is the expected outcome
check(denied.status === 404, `sanctioning officer not in the download groups: ${denied.status}`);
await ctx.close();

// 6. The generation monitor.
ctx = await session('rverma');
await go('/admin/official-documents');
await shot('od-08-monitor');
check((await page.content()).includes('generated'), 'monitor shows the generated document');
await ctx.close();

await browser.close();
if (errors.length) {
    console.error(errors.join('\n'));
    process.exit(1);
}
console.log('official documents scenario passed');
