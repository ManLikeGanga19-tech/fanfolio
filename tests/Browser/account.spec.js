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

    // Wallet & top-up (canvas FanCore), the test checkout, Top-up success (FanMore),
    // Purchases & receipts (FanProfile) and the Currency setting (D-21).
    await page.goto('/wallet');
    await expect(page.getByRole('heading', { name: 'Wallet' })).toBeVisible();
    await expectNoAxeViolations(page);
    await expectVisibleFocusOnTab(page);
    await page.getByText('2,600 credits').click();
    await page.getByRole('button', { name: 'Continue to secure checkout' }).click();
    await expect(page.getByRole('heading', { name: 'Pay $25.00' })).toBeVisible();
    await page.getByRole('button', { name: 'Pay', exact: true }).click();
    // The payments queue applies the webhook; until then the page says it is waiting.
    await expect(async () => {
        if (await page.getByRole('link', { name: 'Check again' }).isVisible()) {
            await page.getByRole('link', { name: 'Check again' }).click();
        }
        await expect(page.getByRole('heading', { name: '2,600 credits added' })).toBeVisible({ timeout: 1000 });
    }).toPass({ timeout: 30_000 });
    await expectNoAxeViolations(page);
    await expectVisibleFocusOnTab(page);
    await page.goto('/account/purchases');
    await expect(page.getByText('+2,600')).toBeVisible();
    await expectNoAxeViolations(page);
    await expectVisibleFocusOnTab(page);
    await page.goto('/account/currency');
    await expectNoAxeViolations(page);
    await page.getByLabel('Kenyan shilling (KSh)').check();
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('link', { name: /Currency\s+KSh/ })).toBeVisible();
    await expect(page.getByText(/≈ KSh/)).toBeVisible();
    await expectNoAxeViolations(page);

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

    // The admin home (canvas AdminOverview).
    await staffPage.goto('/admin');
    await expect(staffPage.getByRole('heading', { name: 'Overview' })).toBeVisible();
    await expectNoAxeViolations(staffPage);
    await expectVisibleFocusOnTab(staffPage);

    // Users & bans (canvas AdminUsers), with this run's creator open in the detail pane.
    await staffPage.goto('/admin/accounts');
    await staffPage.getByRole('searchbox').fill(account.username);
    await staffPage.getByRole('button', { name: `@${account.username}` }).click();
    await expect(staffPage.getByRole('heading', { name: `@${account.username}` })).toBeVisible();
    await expectNoAxeViolations(staffPage);
    await expectVisibleFocusOnTab(staffPage, { maxSteps: 60 });

    await staffPage.goto('/admin/creator-approvals');
    await expect(staffPage.getByRole('heading', { name: 'Creator approvals' })).toBeVisible();
    // Oldest first, so search for this run's creator; the first match opens in the detail pane.
    const handle = account.username.replace('_', '.');
    await staffPage.getByRole('searchbox').fill(handle);
    const detail = staffPage.getByRole('region', { name: 'Applicant detail' });
    await expect(detail.getByRole('heading', { name: new RegExp(`@${handle.replace('.', '\\.')}$`) })).toBeVisible();
    // Checked before acting, so axe never meets a toast that is still fading in.
    await expectNoAxeViolations(staffPage);
    await expectVisibleFocusOnTab(staffPage, { maxSteps: 60 });
    await detail.getByRole('button', { name: 'Assign to me' }).click();
    await expect(detail.getByText('Assigned to you', { exact: true })).toBeVisible();
    await expect(detail.getByRole('button', { name: 'Unassign' })).toBeVisible();
    await detail.getByRole('button', { name: 'Approve creator' }).click();
    // Filament's confirmation modal is an alertdialog (a plain dialog on older versions).
    await staffPage.getByRole('alertdialog').or(staffPage.getByRole('dialog')).getByRole('button', { name: 'Approve', exact: true }).click();
    await expect(staffPage.getByText('Approved.')).toBeVisible();

    // Creators directory, with this run's creator open in the detail pane.
    await staffPage.goto('/admin/creators');
    await staffPage.getByRole('searchbox').fill(handle);
    await staffPage.getByRole('button', { name: new RegExp(`@${handle.replace('.', '\\.')}`) }).click();
    const creatorDetail = staffPage.getByRole('region', { name: 'Creator detail' });
    await expect(creatorDetail.getByRole('heading', { name: new RegExp(`@${handle.replace('.', '\\.')}$`) })).toBeVisible();
    await expect(creatorDetail.getByRole('button', { name: 'Suspend' })).toBeVisible();
    await expectNoAxeViolations(staffPage);
    await expectVisibleFocusOnTab(staffPage, { maxSteps: 60 });

    // Flag this run's account on Users & bans; the case shows on the Moderation queue (canvas AdminModeration).
    await staffPage.goto('/admin/accounts');
    await staffPage.getByRole('searchbox').fill(account.username);
    await staffPage.getByRole('button', { name: `@${account.username}` }).click();
    await staffPage.getByLabel('Reason category').selectOption('spam');
    await staffPage.getByLabel('Reason (required)').fill('Browser walk-through flag');
    await staffPage.getByRole('button', { name: 'Flag for review' }).click();
    await staffPage.getByRole('alertdialog').or(staffPage.getByRole('dialog')).getByRole('button', { name: 'Flag', exact: true }).click();
    await expect(staffPage.getByText('Flagged. The case is in the moderation queue.')).toBeVisible();

    await staffPage.goto('/admin/moderation?tab=staff_flag');
    await expect(staffPage.getByRole('heading', { name: 'Moderation queue' })).toBeVisible();
    await staffPage.getByRole('button', { name: new RegExp(`@${account.username} · account`) }).click();
    const caseDetail = staffPage.getByRole('region', { name: 'Case detail' });
    await expect(caseDetail.getByText('Browser walk-through flag')).toBeVisible();
    await expectNoAxeViolations(staffPage);
    await expectVisibleFocusOnTab(staffPage, { maxSteps: 80 });
    // Close it, so dev queues do not fill up with walk-through cases.
    await caseDetail.getByLabel('Decision note (saved to audit log)').fill('Walk-through case, no action needed');
    await caseDetail.getByRole('button', { name: 'No action' }).click();
    await staffPage.getByRole('alertdialog').or(staffPage.getByRole('dialog')).getByRole('button', { name: 'No action', exact: true }).click();
    await expect(caseDetail.getByText('Decided: No action')).toBeVisible();
    await staffContext.close();

    await page.goto('/creator/status');
    await expect(page.getByRole('heading', { name: "You're approved" })).toBeVisible();
    await expectNoAxeViolations(page);
    await page.goto('/studio');
    await expect(page.getByRole('heading', { name: 'Welcome to Studio' })).toBeVisible();
    await expectNoAxeViolations(page);

    // Studio · Earnings & withdrawals (canvas StudioEarnings, M3 PR 4).
    await page.goto('/studio/earnings');
    await expect(page.getByRole('heading', { name: 'Earnings & withdrawals' })).toBeVisible();
    await expect(page.getByText('Available to withdraw')).toBeVisible();
    await expectNoAxeViolations(page);
    await expectVisibleFocusOnTab(page, { maxSteps: 60 });

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
