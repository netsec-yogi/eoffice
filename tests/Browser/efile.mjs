// Phase 4 scenario through the real UI (make fresh demo first):
// registry diarises a letter -> officer opens a file, files the letter,
// finalises a note, sends it -> HoD acknowledges and records a decision.
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
const go = (path) => page.goto(base + path, { waitUntil: 'networkidle0' });
const settle = () => page.waitForNetworkIdle({ idleTime: 400 });
async function clickText(text, selector = 'button, a') {
    const h = await page.waitForFunction((t, sel) => [...document.querySelectorAll(sel)].find((e) => e.textContent.trim() === t && e.offsetParent !== null), { timeout: 8000 }, text, selector);
    await Promise.all([settle(), h.asElement().click()]);
}
async function fieldIn(dialogTitle, label) {
    // The input that follows a label inside the open dialog.
    return page.waitForFunction((t, l) => {
        const dlg = [...document.querySelectorAll('dialog[open]')].find((d) => d.textContent.includes(t)) ?? document;
        const lab = [...dlg.querySelectorAll('label')].find((x) => x.textContent.trim().replace(/\s*\*$/, '') === l);
        return lab && (document.getElementById(lab.htmlFor) ?? lab.parentElement.querySelector('input, textarea, select'));
    }, { timeout: 5000 }, dialogTitle, label);
}
async function pickUser(scope, query) {
    const input = await page.waitForSelector(`${scope} input[role=combobox]`);
    await input.type(query);
    const option = await page.waitForSelector(`${scope} li[role=option]`, { timeout: 5000 });
    await option.click();
}
const check = (cond, msg) => (cond ? console.log('✓', msg) : (errors.push(`ASSERTION: ${msg}`), console.error('✗', msg)));

// 1. Registry diarises an incoming letter marked to Anita.
let ctx = await session('rverma');
await go('/correspondence');
await clickText('Register');
await (await fieldIn('Register correspondence', 'Subject')).type('Offer of research collaboration');
await (await fieldIn('Register correspondence', 'From')).type('Example University');
await (await fieldIn('Register correspondence', 'Their reference')).type('EU/RC/2026/41');
await pickUser('dialog[open]', 'asharma');
await shot('ef-01-diarise');
await clickText('Register', 'dialog[open] button');
check((await page.content()).includes('DIARY/'), 'letter diarised with a diary number');
await ctx.close();

// 2. Anita opens a file, files the letter, writes and finalises a note, sends it.
ctx = await session('asharma');
await go('/files/new');
await page.type('input[maxlength="500"]', 'Research collaboration with Example University');
const selects = await page.$$('select');
await selects[0].select((await selects[0].$$eval('option', (o) => o.find((x) => x.textContent.includes('Academic'))?.value)) ?? '');
await clickText('Open file');
await page.waitForFunction(() => /^\/files\/\d+$/.test(location.pathname));
const filePath = new URL(page.url()).pathname;
await go('/correspondence?mine=1');
await clickText('Put on file');
const fileSelect = await page.waitForSelector('dialog[open] select');
await fileSelect.select(await fileSelect.$$eval('option', (o) => o.find((x) => x.value)?.value));
await clickText('Put on file', 'dialog[open] button');
await go(filePath);
await page.type('#note-body', 'The letter from Example University (DIARY above) proposes joint supervision of two PhD scholars. Recommended for approval.');
await clickText('Finalise note');
await clickText('Finalise', 'dialog[open] button');
check((await page.content()).includes('Note 1'), 'note finalised and numbered');
await shot('ef-02-note-sheet');
await clickText('Send file');
await pickUser('dialog[open]', 'pkumar');
const forSelect = (await page.$$('dialog[open] select'))[0];
await forSelect.select('approval');
await clickText('Send', 'dialog[open] button');
check(page.url().endsWith('/files'), 'returned to files after sending');
await go(filePath);
check((await page.content()).includes('In transit to'), 'file shows as in transit');
await ctx.close();

// 3. HoD acknowledges and records the decision.
ctx = await session('pkumar');
await go('/files?tab=incoming');
await shot('ef-03-incoming');
await go(filePath);
await clickText('Acknowledge receipt');
const kind = await page.waitForSelector('form select');
await kind.select('decision');
await page.type('#note-body', 'Approved. Registrar may sign the MoU.');
await clickText('Finalise note');
await clickText('Finalise', 'dialog[open] button');
check((await page.content()).includes('Note 2'), 'decision recorded as note 2');
await shot('ef-04-decision');
await clickText('History', 'button');
await shot('ef-05-history');
const history = await page.content();
check(['File opened', 'Placed on file', 'Sent to Priya Kumar', 'Received by Priya Kumar', 'Decision #2'].every((t) => history.includes(t)), 'history records every step');
await ctx.close();

// 4. Records officer's view.
ctx = await session('rverma');
await go('/records');
await shot('ef-06-records');
await go('/files?tab=register');
await shot('ef-07-register');
await ctx.close();

await browser.close();
if (errors.length) {
    console.error('\nErrors:\n' + errors.join('\n'));
    process.exit(1);
}
console.log('\nE-file scenario passed.');
