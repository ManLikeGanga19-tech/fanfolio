import { createHmac, randomBytes } from 'node:crypto';
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
