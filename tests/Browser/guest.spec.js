import { expect, test } from '@playwright/test';
import { expectNoAxeViolations, expectVisibleFocusOnTab } from './support.js';

// Every page a signed-out visitor can open.
const pages = [
    ['Landing', '/'],
    ['Sign up', '/signup'],
    ['Log in', '/login'],
    ['Forgot password', '/forgot-password'],
    ['Choose a new password', '/reset-password/not-a-real-token?email=someone%40example.test'],
    ['Terms', '/terms'],
    ['Privacy', '/privacy'],
    ['Records notice', '/records-notice'],
];

for (const [name, path] of pages) {
    test(`${name} has no axe violations`, async ({ page }) => {
        await page.goto(path);
        await expectNoAxeViolations(page);
    });
}

test('validation errors are announced and pass axe', async ({ page }) => {
    await page.goto('/signup');
    await page.getByRole('button', { name: 'Continue', exact: true }).click();

    await expect(page.getByRole('alert').first()).toBeVisible();
    await expect(page.getByLabel('Email')).toHaveAttribute('aria-invalid', 'true');
    await expectNoAxeViolations(page);
});

test('the first Tab reaches "Skip to content", which moves focus to the page', async ({ page }) => {
    await page.goto('/login');
    await page.keyboard.press('Tab');

    const skip = page.getByRole('link', { name: 'Skip to content' });
    await expect(skip).toBeFocused();
    await expect(skip).toBeInViewport();

    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/#main$/);
});

for (const path of ['/', '/signup', '/login', '/forgot-password']) {
    test(`every control on ${path} shows a focus ring when tabbed to`, async ({ page }) => {
        await page.goto(path);
        const focused = await expectVisibleFocusOnTab(page);

        expect(focused.length).toBeGreaterThan(2);
    });
}

test('sign up works from the keyboard alone', async ({ page }) => {
    await page.goto('/signup');
    await page.getByLabel('Email').focus();

    await page.keyboard.type('keyboard.only@example.test');
    await page.keyboard.press('Tab');
    await page.keyboard.type('keyboard_only');
    await page.keyboard.press('Tab');
    await page.keyboard.type('short');
    await page.keyboard.press('Tab');
    await page.keyboard.press('Space');
    await expect(page.getByRole('checkbox')).toBeChecked();
    // Past the Terms and Privacy links in the checkbox label.
    await page.keyboard.press('Tab');
    await page.keyboard.press('Tab');
    await page.keyboard.press('Tab');
    await expect(page.getByRole('button', { name: 'Continue', exact: true })).toBeFocused();
    await page.keyboard.press('Enter');

    // Too short: the server says so and nothing is created.
    await expect(page.getByText('At least 12 characters.')).toBeVisible();
});
