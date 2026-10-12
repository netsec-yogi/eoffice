// End-to-end browser scenario through the real UI and the development
// directory (make fresh demo first). Fails on any browser console error.
//   node tests/Browser/flow.mjs [baseUrl] [outDir]
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
    await page.goto(`${base}/login`, { waitUntil: 'networkidle0' });
    await page.type('input[autocomplete=username]', username);
    await page.type('input[type=password]', 'Dev-Password-2026');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('button[type=submit]')]);
    return ctx;
}
const shot = (name) => page.screenshot({ path: `${out}/${name}.png`, fullPage: true });
async function go(path) {
    await page.goto(base + path, { waitUntil: 'networkidle0' });
}
async function clickText(text, selector = 'button, a') {
    const handle = await page.waitForFunction(
        (t, sel) => [...document.querySelectorAll(sel)].find((e) => e.textContent.trim() === t && e.offsetParent !== null),
        { timeout: 8000 },
        text,
        selector,
    );
    await Promise.all([page.waitForNetworkIdle({ idleTime: 400 }), handle.asElement().click()]);
}
function check(cond, msg) {
    if (!cond) {
        errors.push(`ASSERTION: ${msg}`);
        console.error('✗', msg);
    } else console.log('✓', msg);
}

// 1. Initiator: catalogue, form, submit.
let ctx = await session('asharma');
await go('/requests/new');
await shot('flow-01-catalogue');
await clickText('Institute e-mail account', 'p');
await page.waitForSelector('textarea');
await page.type('textarea', 'Joining the CSE department; need an official mailbox.');
await page.type('input[type=email]', 'anita.personal@example.com');
await page.click('input[type=checkbox]');
await shot('flow-02-form');
await clickText('Submit');
await page.waitForFunction(() => location.pathname.match(/^\/requests\/\d+$/));
const requestPath = new URL(page.url()).pathname;
await shot('flow-03-submitted');
check((await page.content()).includes('Recommendation'), 'request shows the Recommendation step as current');
await ctx.close();

// 2. HoD (reporting officer): inbox, open, recommend.
ctx = await session('pkumar');
await go('/tasks');
await shot('flow-04-hod-inbox');
await go(requestPath);
await shot('flow-05-hod-decision');
await clickText('Recommend');
check(page.url().endsWith('/tasks'), 'HoD returned to the inbox after deciding');
await ctx.close();

// 3. Computer Centre: verify; the mailbox step has no adapter -> manual task.
ctx = await session('rverma');
await go(requestPath);
await clickText('Verified');
// The mailbox step runs on the queue: wait for its outcome.
for (let i = 0; i < 20; i++) {
    await go(requestPath);
    if ((await page.content()).includes('Mark as done')) break;
    await new Promise((r) => setTimeout(r, 500));
}
await shot('flow-06-cc-manual');
check((await page.content()).includes('Mark as done'), 'mailbox step became a manual task (no fake success)');
await page.type('textarea', 'Created in the mail console as anita.sharma@institute.test');
await clickText('Mark as done');
await go(requestPath);
await shot('flow-07-completed');
check((await page.content()).includes('Approved'), 'request completed');

// 4. Administration screens.
for (const [path, name] of [
    ['/', 'dash-admin'],
    ['/monitor', 'monitor'],
    ['/computer-centre/accounts', 'cc-ledger'],
    ['/computer-centre/assets', 'cc-assets'],
    ['/admin/workflows', 'admin-workflows'],
    ['/admin/forms', 'admin-forms'],
    ['/admin/audit', 'admin-audit'],
]) {
    await go(path);
    await shot(`flow-08-${name}`);
}
await page.goto(`${base}/admin/workflows`, { waitUntil: 'networkidle0' });
await clickText('Server / virtual machine');
await new Promise((r) => setTimeout(r, 800));
await shot('flow-09-designer');
await clickText('Check quota', 'p');
await shot('flow-10-designer-step');
await go('/admin/forms');
await clickText('Server / virtual machine');
await shot('flow-11-form-builder');
await clickText('Preview');
await shot('flow-12-form-preview');
await ctx.close();

await browser.close();
if (errors.length) {
    console.error('\nErrors:\n' + errors.join('\n'));
    process.exit(1);
}
console.log('\nFlow passed.');
