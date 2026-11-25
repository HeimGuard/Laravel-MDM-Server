// Takes the screenshots of the README (dark mode) from a running portal with the seeded data (`php artisan db:seed`).
//
//   BASE_URL=http://127.0.0.1:8000 OUT=docs/screenshots node docs/screenshots/shoot.cjs
//
// The workflow `Screenshots` (.github/workflows/screenshots.yml) does all of it: it installs the portal, seeds it, runs this and
// commits the pictures. Run by hand: the first account is created on /setup (e-mail admin@example.com, password `password-123`).
const { chromium } = require('playwright');

const base = process.env.BASE_URL || 'http://127.0.0.1:8000';
const out = process.env.OUT || 'docs/screenshots';
const user = { name: 'Admin', email: 'admin@example.com', password: 'password-123' };

/** Dark mode: the attribute the layout sets from the cookie `theme`, and the colour scheme of the browser. */
async function dark(page) {
    await page.evaluate(() => document.documentElement.setAttribute('data-bs-theme', 'dark'));
}

async function settle(page, ms = 900) {
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(ms);
    await dark(page);
}

async function shot(page, name) {
    await settle(page);
    await page.screenshot({ path: `${out}/${name}.png` });
    console.log('saved', name);
}

/** Opens a device of the list by its name. */
async function device(page, name, tab = null) {
    await page.goto(`${base}/devices`);
    await settle(page, 400);
    await page.getByText(new RegExp(`^\\s*${name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*$`, 'i')).first().click();
    await settle(page, 1500);
    if (tab) {
        await page.getByRole('tab', { name: new RegExp(tab, 'i') }).or(page.getByText(new RegExp(`^\\s*${tab}`, 'i')).first()).first().click();
        await settle(page, 900);
    }
}

(async () => {
    const browser = await chromium.launch();
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: 'dark' });
    await context.addCookies([{ name: 'theme', value: 'dark', url: base }]);
    const page = await context.newPage();
    page.on('pageerror', (error) => console.log('page error:', error.message));

    // The first account (while there is none, /setup creates it).
    await page.goto(`${base}/setup`);
    if (page.url().includes('/setup')) {
        await page.fill('#name', user.name);
        await page.fill('#email', user.email);
        await page.fill('#password', user.password);
        await page.fill('#password_confirmation', user.password);
        await Promise.all([page.waitForURL((url) => !url.pathname.includes('/setup')), page.locator('form button[type=submit], form input[type=submit]').first().click()]);
    }

    // The devices of the seeders (DeviceSeeder, NetworkMapSeeder).
    await device(page, 'nas');
    await shot(page, 'device');
    await device(page, 'asus-pc-2', 'Updates');
    await shot(page, 'device-updates');
    await device(page, 'Tiskárna');
    await shot(page, 'ping');
    // A router read over SNMP: its ports.
    try {
        await device(page, 'Turris');
        await shot(page, 'device-ports');
    } catch (error) {
        console.log('no Turris card:', error.message);
    }

    await page.goto(`${base}/devices`);
    await settle(page, 400);
    await page.getByText('Add device').first().click();
    await page.getByText('Enrol new device').waitFor({ timeout: 15000 });
    await shot(page, 'enrolment');

    await page.goto(`${base}/networks`);
    await page.locator('.network-map').waitFor({ timeout: 20000 });
    await page.waitForTimeout(1500);
    await shot(page, 'networks');
    // The list of the networks with their unknown devices.
    await page.getByRole('link', { name: /^\s*Networks/ }).or(page.getByRole('button', { name: /^\s*Networks/ })).first().click();
    await shot(page, 'network-discovery');

    await page.goto(`${base}/notifications`);
    await shot(page, 'notifications');
    await page.goto(`${base}/security`);
    await shot(page, 'security');
    await page.goto(`${base}/dashboard`);
    await shot(page, 'dashboard');

    // A phone.
    const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, colorScheme: 'dark' });
    await phone.addCookies(await context.cookies());
    const small = await phone.newPage();
    await small.goto(`${base}/devices`);
    await settle(small, 600);
    await small.getByText(/^\s*nas\s*$/i).first().click();
    await shot(small, 'mobile');

    // The login page (no session).
    const guest = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: 'dark' });
    await guest.addCookies([{ name: 'theme', value: 'dark', url: base }]);
    const login = await guest.newPage();
    await login.goto(`${base}/login`);
    await shot(login, 'login');

    await browser.close();
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
