import { test } from '@playwright/test';

// Screenshots of the auth screens for review (uploaded by CI as an artifact).
// Not an assertion: the axe and focus checks are the gate.
for (const path of ['login', 'signup', 'forgot-password']) {
    test(`screenshot of /${path}`, async ({ page }, testInfo) => {
        await page.goto(`/${path}`);
        await page.waitForLoadState('networkidle');
        await page.screenshot({ path: `screenshots/${path}-${testInfo.project.name}.png`, fullPage: true });
    });
}
