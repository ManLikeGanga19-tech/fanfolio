import { execFileSync } from 'node:child_process';
import { createHmac, randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import AxeBuilder from '@axe-core/playwright';
import { expect } from '@playwright/test';

// WCAG 2.2 A and AA, the level the design system targets.
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa'];

/**
 * Fails with a readable list of every axe violation on the current page.
 */
export async function expectNoAxeViolations(page) {
    const { violations } = await new AxeBuilder({ page }).withTags(TAGS).analyze();

    const report = violations.map((v) => `${v.id} (${v.impact}): ${v.help}\n  ${v.nodes.map((n) => n.target.join(' ')).join('\n  ')}`);

    expect(report, `axe violations on ${page.url()}`).toEqual([]);
}

/**
 * Tabs through the page and checks every focused element shows the design-system
 * focus ring (2px outline) and that focus never gets stuck. Returns what was focused.
 */
export async function expectVisibleFocusOnTab(page, { maxSteps = 40 } = {}) {
    const seen = [];

    for (let step = 0; step < maxSteps; step++) {
        await page.keyboard.press('Tab');

        const focused = await page.evaluate(() => {
            const el = document.activeElement;
            if (!el || el === document.body) {
                return null;
            }
            const style = getComputedStyle(el);
            const rect = el.getBoundingClientRect();

            return {
                key: `${el.tagName}|${el.getAttribute('name') ?? ''}|${el.textContent?.trim().slice(0, 40) ?? ''}|${el.getAttribute('href') ?? ''}`,
                label: el.getAttribute('aria-label') ?? el.textContent?.trim().slice(0, 40) ?? el.tagName,
                outlineStyle: style.outlineStyle,
                outlineWidth: parseFloat(style.outlineWidth),
                visible: rect.width > 0 && rect.height > 0,
            };
        });

        if (focused === null) {
            break; // Focus left the document: the end of the tab order.
        }
        if (seen.includes(focused.key)) {
            break; // Wrapped around.
        }
        seen.push(focused.key);

        expect(focused.visible, `focused element is visible: ${focused.label}`).toBe(true);
        expect(focused.outlineStyle, `focus ring on: ${focused.label}`).not.toBe('none');
        expect(focused.outlineWidth, `focus ring width on: ${focused.label}`).toBeGreaterThanOrEqual(2);
    }

    return seen;
}

/** A fresh, obviously fake account for each run. */
export function fakeAccount() {
    const id = randomBytes(4).toString('hex');

    return {
        email: `a11y.${id}@example.test`,
        username: `a11y_${id}`,
        password: `a11y-check-${id}-not-real`,
    };
}

/** RFC 6238 code for a base32 secret (the authenticator app set-up key). */
export function totpCode(base32Secret, at = Date.now()) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of base32Secret.replace(/[\s=]/g, '').toUpperCase()) {
        bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
    }
    const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));

    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(at / 1000 / 30)));

    const hmac = createHmac('sha1', key).update(counter).digest();
    const offset = hmac[hmac.length - 1] & 0x0f;
    const code = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;

    return code.toString().padStart(6, '0');
}

/** Signs up a fresh fan through the real sign-up screen (step 1). */
export async function signUp(page, account) {
    await page.goto('/signup');
    await page.getByLabel('Email').fill(account.email);
    await page.getByLabel('Username').fill(account.username);
    await page.getByLabel('Password', { exact: true }).fill(account.password);
    await page.getByLabel('Confirm password', { exact: true }).fill(account.password);
    await page.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(page).toHaveURL(/\/signup\/email$/);
}

/** Turns on an authenticator app from Devices & security; returns its set-up key. */
export async function setUpAuthenticator(page) {
    await page.goto('/account/security');
    await page.getByRole('button', { name: 'Set up' }).click();
    await expect(page.getByRole('heading', { name: 'Set up authenticator app' })).toBeVisible();

    const secret = (await page.getByLabel('Set-up key').textContent()).trim();
    await page.getByLabel('6-digit code').fill(totpCode(secret));
    await page.getByRole('button', { name: 'Turn on' }).click();
    await expect(page.getByRole('heading', { name: 'Save your recovery codes' })).toBeVisible();

    return secret;
}

/**
 * Decision D-14: makes an account staff with the development-only command. By default it
 * runs `php artisan` here, with the database settings this process inherits. With the
 * Docker stack, set A11Y_ARTISAN="docker compose exec -T app php artisan" to run it in
 * the app container instead.
 */
export function makeStaff(email, role = 'admin') {
    const [command, ...prefix] = (process.env.A11Y_ARTISAN ?? 'php artisan').trim().split(/\s+/);
    execFileSync(command, [...prefix, 'fanfolio:dev-staff', email, `--role=${role}`], {
        cwd: fileURLToPath(new URL('../..', import.meta.url)),
        stdio: 'pipe',
    });
}

/**
 * Starts the ID and selfie check and follows it to the end with the Fake provider
 * (D-14): a computer gets a QR code and, in development mode, "continue on this
 * computer"; the development stand-in capture page accepts anything.
 */
export async function passIdCheck(page) {
    await page.getByRole('button', { name: 'Start ID check' }).click();

    const onComputer = page.getByRole('heading', { name: 'Continue on your phone' });
    const standIn = page.getByText('Development stand-in for the verification partner');
    const matched = page.getByRole('heading', { name: 'ID and selfie matched' });
    await expect(onComputer.or(standIn).or(matched)).toBeVisible();

    if (await onComputer.isVisible()) {
        await expectNoAxeViolations(page);
        await page.getByRole('link', { name: 'Continue on this computer (development only)' }).click();
        await expect(standIn.or(matched)).toBeVisible();
    }

    if (await standIn.isVisible()) {
        await expectNoAxeViolations(page);
        await expectVisibleFocusOnTab(page);
        await page.getByRole('button', { name: 'Finish' }).click();
    }

    await expect(matched).toBeVisible();
}
