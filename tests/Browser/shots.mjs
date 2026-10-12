// Visual check: signs in through the real directory and screenshots pages.
// Usage: node tests/Browser/shots.mjs [baseUrl] [outDir]   (needs make fresh demo)
import puppeteer from 'puppeteer-core';
import fs from 'node:fs';

const base = process.argv[2] ?? 'http://localhost:8100';
const out = process.argv[3] ?? 'storage/shots';
const pages = (process.env.SHOTS ?? '/,/admin/users,/admin/authorities,/admin/committees,/admin/audit').split(',');
const executablePath = process.env.CHROME ?? ['/usr/bin/google-chrome', '/usr/bin/chromium-browser'].find((p) => fs.existsSync(p));

fs.mkdirSync(out, { recursive: true });
const browser = await puppeteer.launch({ executablePath, headless: true, args: ['--no-sandbox'] });
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
await page.setViewport({ width: Number(process.env.WIDTH ?? 1440), height: 900 });

await page.goto(`${base}/login`, { waitUntil: 'networkidle0' });
await page.screenshot({ path: `${out}/login.png` });
await page.type('input[autocomplete=username]', process.env.USERNAME_ ?? 'rverma');
await page.type('input[type=password]', process.env.PASSWORD_ ?? 'Dev-Password-2026');
await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('button[type=submit]')]);

for (const path of pages) {
    await page.goto(base + path, { waitUntil: 'networkidle0' });
    const name = path.replace(/\//g, '_').replace(/^_$/, 'dashboard').replace(/^_/, '') || 'dashboard';
    await page.screenshot({ path: `${out}/${name}.png`, fullPage: true });
    console.log('shot', path, page.url());
}
await browser.close();
if (errors.length) {
    console.error('Browser errors:\n' + errors.join('\n'));
    process.exit(1);
}
