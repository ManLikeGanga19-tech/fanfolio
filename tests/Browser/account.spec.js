import { expect, test } from '@playwright/test';
import {
    expectNoAxeViolations, expectVisibleFocusOnTab, fakeAccount, makeStaff, passIdCheck, setUpAuthenticator, signUp, totpCode,
} from './support.js';

// Signed-in pages, reached through the real flows with a fresh fake account: sign up,
// email code, age check, account hub, security, step-up, authenticator set-up, recovery
// codes, creator onboarding, staff approval in /admin, /studio, then the second-factor
// screen at log in.
test.describe.configure({ mode: 'serial' });

test('signed-in pages pass axe and keep a visible focus ring', async ({ page, browser }) => {
    // Two accounts and about twenty pages, on a dev server reading from OneDrive.
    test.setTimeout(420_000);
    const account = fakeAccount();

    await page.goto('/signup');
    await page.getByLabel('Email').fill(account.email);
    await page.getByLabel('Username').fill(account.username);
    await page.getByLabel('Password', { exact: true }).fill(account.password);
    await page.getByLabel('Confirm password', { exact: true }).fill(account.password);
    await page.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Continue', exact: true }).click();

    // Sign up step 2: the code was emailed with the sign-up.
    await expect(page).toHaveURL(/\/signup\/email$/);
    await expect(page.getByRole('heading', { name: 'Verify your email' })).toBeVisible();
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

    // Creator onboarding (needs MFA, which the account now has).
    await page.goto('/creator/start');
    await expectNoAxeViolations(page);
    await expectVisibleFocusOnTab(page);
    await page.getByRole('button', { name: 'Start verification' }).click();

    await expect(page.getByRole('heading', { name: 'Set up your page' })).toBeVisible();
    await expectNoAxeViolations(page);
    await expectVisibleFocusOnTab(page);

    await page.getByLabel('Display name').fill('Test Creator');
    await page.getByLabel('Handle').fill(account.username.replace('_', '.'));
    await expect(page.getByText('Available')).toBeVisible();
    await page.getByLabel('Category').selectOption('music');
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page.getByText('Saved. Next: verify your identity.')).toBeVisible();

    // Steps 2–3: ID and selfie, on a phone or through the QR code on a computer (D-14).
    await expect(page.getByRole('heading', { name: 'Scan your ID' })).toBeVisible();
    await expectNoAxeViolations(page);
    await expectVisibleFocusOnTab(page);
    await passIdCheck(page);
    await expectNoAxeViolations(page);
    await page.getByRole('link', { name: 'Continue' }).click();

    // Step 4: payout method. Signing in is not enough: confirm with the authenticator app
    // (D-13). The next 30-second step, since the set-up code's step cannot be used twice.
    await expect(page.getByRole('heading', { name: "Confirm it's you" })).toBeVisible();
    await expect(page.getByText('Payouts need your passkey or authenticator app')).toBeVisible();
    await expectNoAxeViolations(page);
    await page.getByLabel('Code from your authenticator app').fill(totpCode(secret, Date.now() + 30_000));
    await page.getByRole('button', { name: 'Confirm' }).click();

    await expect(page.getByRole('heading', { name: 'Where should we send your money?' })).toBeVisible();
    await expectNoAxeViolations(page);
    await expectVisibleFocusOnTab(page);
    await page.getByLabel('Bank', { exact: true }).fill('Test Bank');
    await page.getByLabel('Account number').fill('000011112222');
    await page.getByRole('button', { name: 'Review and submit' }).click();

    // Review & submit.
    await expect(page.getByRole('heading', { name: 'Check everything' })).toBeVisible();
    await expectNoAxeViolations(page);
    await expectVisibleFocusOnTab(page);
    await page.getByLabel(/I agree to the/).check();
    await page.getByLabel(/Everyone in my content is 18\+/).check();
    await page.getByRole('button', { name: 'Submit for review' }).click();
    await expect(page.getByRole('heading', { name: "We're reviewing your account" })).toBeVisible();
    await expectNoAxeViolations(page);

    // Staff approve the application in /admin (a second account, made staff with the
    // development-only command, D-14), then the creator opens /studio.
    const staffContext = await browser.newContext({ ...test.info().project.use });
    const staffPage = await staffContext.newPage();
    const staff = fakeAccount();
    await signUp(staffPage, staff);
    makeStaff(staff.email);
    await setUpAuthenticator(staffPage);

    await staffPage.goto('/admin/creator-approvals');
    await expect(staffPage.getByRole('heading', { name: 'Creators awaiting review' })).toBeVisible();
    await expectNoAxeViolations(staffPage);
    // Oldest first, so search for this run's creator.
    const handle = account.username.replace('_', '.');
    await staffPage.getByRole('searchbox').fill(handle);
    const row = staffPage.getByRole('row').filter({ hasText: `@${handle}` });
    await row.getByRole('button', { name: 'Approve' }).click();
    // Filament's confirmation modal is an alertdialog (a plain dialog on older versions).
    await staffPage.getByRole('alertdialog').or(staffPage.getByRole('dialog')).getByRole('button', { name: 'Confirm' }).click();
    await expect(staffPage.getByText('Approved.')).toBeVisible();
    await staffContext.close();

    await page.goto('/creator/status');
    await expect(page.getByRole('heading', { name: "You're approved" })).toBeVisible();
    await expectNoAxeViolations(page);
    await page.goto('/studio');
    await expect(page.getByRole('heading', { name: 'Welcome to Studio' })).toBeVisible();
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
