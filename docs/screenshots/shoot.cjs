// Takes the screenshots of the README (dark mode) from a running portal with the seeded data (`php artisan db:seed`).
//
//   BASE_URL=http://127.0.0.1:8000 OUT=docs/screenshots node docs/screenshots/shoot.cjs
//
// The workflow `Screenshots` (.github/workflows/screenshots.yml) does all of it: it installs the portal, seeds it, runs this and
// commits the pictures. Run by hand: the first account is created on /setup (e-mail admin@example.com, password `password-123`).
const { chromium } = require('playwright');

const base = process.env.BASE_URL || 'http://127.0.0.1:8000';
const out = process.env.OUT || 'docs/screenshots';
const debugDir = process.env.DEBUG_DIR || '';
const failed = [];
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
    // From the top: the page and every box that scrolled (the list of the devices scrolls the selected one into view).
    await page.evaluate(() => {
        window.scrollTo(0, 0);
        document.querySelectorAll('*').forEach((element) => {
            if (element.scrollTop > 0) element.scrollTop = 0;
        });
    });
    await page.waitForTimeout(200);
    await page.screenshot({ path: `${out}/${name}.png` });
    console.log('saved', name);
}

/** Opens a device of the list by its name; `tab` (a part of its label) opens that tab, false when the device has none. */
async function device(page, name, tab = null) {
    await page.goto(`${base}/devices`);
    await settle(page, 400);
    const escaped = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    await page.getByText(new RegExp(`^\\s*${escaped(name)}\\s*$`, 'i')).first().click({ timeout: 15000 });
    await settle(page, 1500);
    if (!tab) {
        return true;
    }
    const target = page.getByRole('tab', { name: new RegExp(escaped(tab), 'i') }).or(page.getByText(new RegExp(`^\\s*${escaped(tab)}`, 'i'))).first();
    try {
        await target.click({ timeout: 4000 });
    } catch (error) {
        return false;
    }
    await settle(page, 900);

    return true;
}

/** One picture: a failure is written down (and a picture of the page kept for the artifact) and the others go on. */
async function step(page, name, work) {
    try {
        await work();
    } catch (error) {
        failed.push(name);
        console.log(`FAILED ${name}: ${String(error.message).split('\n')[0]}`);
        if (debugDir) {
            await page.screenshot({ path: `${debugDir}/failed-${name}.png` }).catch(() => {});
        }
    }
}

(async () => {
    const browser = await chromium.launch();
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, colorScheme: 'dark' });
    await context.addCookies([{ name: 'theme', value: 'dark', url: base }]);
    const page = await context.newPage();
    page.on('pageerror', (error) => console.log('page error:', error.message, '|', String(error.stack || '').split('\n').slice(1, 3).join(' ').trim(), '|', page.url()));

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
    await step(page, 'device', async () => {
        await device(page, 'nas');
        await shot(page, 'device');
    });
    await step(page, 'device-drives', async () => {
        await device(page, 'nas', 'Drives');
        await shot(page, 'device-drives');
    });
    await step(page, 'ping', async () => {
        await device(page, 'Tiskárna');
        await shot(page, 'ping');
    });
    // A router read over SNMP: its ports.
    await step(page, 'device-ports', async () => {
        await device(page, 'Turris');
        await shot(page, 'device-ports');
    });
    await step(page, 'enrolment', async () => {
        await page.goto(`${base}/devices`);
        await settle(page, 400);
        await page.getByText('Add device').first().click({ timeout: 15000 });
        await page.getByText('Enrol new device').waitFor({ timeout: 15000 });
        await shot(page, 'enrolment');
    });
    await step(page, 'networks', async () => {
        await page.goto(`${base}/networks`);
        await page.locator('.network-map').waitFor({ timeout: 20000 });
        await page.waitForTimeout(1500);
        // Everything in the picture, not the part the map opened with.
        await page.locator('[data-zoom="fit"]').click();
        await page.waitForTimeout(800);
        // Then closer to the network (the wheel zooms where the pointer is): the text of the cards is readable in the README.
        const frame = await page.locator('.nm-frame-label').first().boundingBox();
        if (frame) {
            await page.mouse.move(frame.x + 380, frame.y - 40);
            for (let i = 0; i < 3; i++) {
                await page.mouse.wheel(0, -250);
                await page.waitForTimeout(250);
            }
            await page.waitForTimeout(500);
        }
        await shot(page, 'networks');
    });
    // The list of the networks with their unknown devices.
    await step(page, 'network-discovery', async () => {
        await page.getByText(/^\s*Network table/).first().click({ timeout: 10000 });
        await page.waitForTimeout(800);
        await shot(page, 'network-discovery');
    });
    for (const [name, path] of [['notifications', '/notifications'], ['security', '/security'], ['dashboard', '/dashboard']]) {
        await step(page, name, async () => {
            await page.goto(`${base}${path}`);
            await shot(page, name);
        });
    }

    // A phone.
    const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, colorScheme: 'dark' });
    await phone.addCookies(await context.cookies());
    const small = await phone.newPage();
    await step(small, 'mobile', async () => {
        await small.goto(`${base}/devices`);
        await settle(small, 600);
        try {
            await small.getByText(/^\s*nas\s*$/i).locator('visible=true').first().click({ timeout: 8000 });
        } catch (error) {
            console.log('mobile: the list only:', String(error.message).split('\n')[0]);
        }
        await shot(small, 'mobile');
    });

    // The login page (no session).
    const guest = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: 'dark' });
    await guest.addCookies([{ name: 'theme', value: 'dark', url: base }]);
    const login = await guest.newPage();
    await step(login, 'login', async () => {
        await login.goto(`${base}/login`);
        await shot(login, 'login');
    });

    await browser.close();
    if (failed.length) {
        console.log('Not taken:', failed.join(', '));
    }
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
