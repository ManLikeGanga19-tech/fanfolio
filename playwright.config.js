import { defineConfig, devices } from '@playwright/test';

// Accessibility gate (brief: "Automated axe checks on every page; keyboard and
// focus-visible checks on the fan PWA"). Runs against a running app, never a real
// outside service: the app uses its Fake drivers.
export default defineConfig({
    testDir: 'tests/Browser',
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: 0,
    // Generous: the signed-in walk-through visits about a dozen pages.
    timeout: 180_000,
    expect: { timeout: 15_000 },
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: process.env.A11Y_BASE_URL ?? 'http://localhost:8088',
        trace: 'retain-on-failure',
    },
    projects: [
        // A phone first: the fan PWA is mobile-first.
        { name: 'mobile', use: { ...devices['Pixel 7'] } },
        { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
    ],
});
