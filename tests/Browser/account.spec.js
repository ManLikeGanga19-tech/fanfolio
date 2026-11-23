import { expect, test } from '@playwright/test';
import { expectNoAxeViolations, expectVisibleFocusOnTab, fakeAccount, totpCode } from './support.js';

// Signed-in pages, reached through the real flows with a fresh fake account: sign up,
// phone, age check, account hub, security, step-up, authenticator set-up, recovery
// codes, then the second-factor screen at log in.
test.describe.configure({ mode: 'serial' });

test('signed-in pages pass axe and keep a visible focus ring', async ({ page }) => {
    const account = fakeAccount();

    await page.goto('/signup');
    await page.getByLabel('Email').fill(account.email);
    await page.getByLabel('Username').fill(account.username);
    await page.getByLabel('Password', { exact: true }).fill(account.password);
    await page.getByLabel('Confirm password', { exact: true }).fill(account.password);
    await page.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Continue', exact: true }).click();

    // Sign up step 2.
    await expect(page).toHaveURL(/\/signup\/phone$/);
    await expectNoAxeViolations(page);
    await expectVisibleFocusOnTab(page);

    // Sign up step 3.
    await page.goto('/signup/age');
    await expectNoAxeViolations(page);
    await expectVisibleFocusOnTab(page);

    await page.goto('/account');
    await expectNoAxeViolations(page);
    await expectVisibleFocusOnTab(page);

    await page.goto('/account/security');
    await expectNoAxeViolations(page);
    await expectVisibleFocusOnTab(page);

    await page.goto('/account/confirm');
    await expectNoAxeViolations(page);

    // Authenticator app set-up (signing in counts as a fresh step-up).
    await page.goto('/account/security');
    await page.getByRole('button', { name: 'Set up' }).click();
    await expect(page.getByRole('heading', { name: 'Set up authenticator app' })).toBeVisible();
    await expectNoAxeViolations(page);

    const secret = (await page.getByLabel('Set-up key').textContent()).trim();
    await page.getByLabel('6-digit code').fill(totpCode(secret));
    await page.getByRole('button', { name: 'Turn on' }).click();

    await expect(page.getByRole('heading', { name: 'Save your recovery codes' })).toBeVisible();
    await expectNoAxeViolations(page);

    // Log out, log back in: the second-factor screen.
    await page.goto('/account');
    await page.getByRole('button', { name: 'Log out' }).click();
    await page.goto('/login');
    await page.getByLabel('Email').fill(account.email);
    await page.getByLabel('Password', { exact: true }).fill(account.password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click();

    await expect(page).toHaveURL(/\/login\/mfa$/);
    await expectNoAxeViolations(page);
    await expectVisibleFocusOnTab(page);
});
