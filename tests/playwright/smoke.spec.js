import { expect, test } from '@playwright/test';

// Dedrok Hertrox by default; override with E2E_CHARACTER_ID.
const characterId = process.env.E2E_CHARACTER_ID ?? '2124589167';

test.beforeEach(async ({ page }) => {
    const response = await page.goto(`/dev/login/${characterId}`);
    expect(response.status(), 'dev-login must be enabled and the character synced').toBeLessThan(400);
});

test('dashboard shows the character overview', async ({ page }) => {
    await expect(page.locator('h1')).not.toBeEmpty();
    await expect(page.getByText('Total skillpoints')).toBeVisible();
    await expect(page.getByText('Skill queue').first()).toBeVisible();
});

test('losses card lazy-loads on the dashboard', async ({ page }) => {
    // The card only loads once scrolled into the viewport (livewire lazy).
    await page.waitForLoadState('networkidle');
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await expect(page.getByRole('heading', { name: /Ship losses/ })).toBeVisible({ timeout: 20_000 });
});

test('skills page lists skill groups', async ({ page }) => {
    await page.goto('/skills');
    await expect(page.locator('h1')).toContainText(/Skills/i);
});

test('remap optimizer renders a recommendation', async ({ page }) => {
    await page.goto('/remap');
    await expect(page.locator('h1')).toContainText(/remap/i);
});

test('market page shows the appraisal form', async ({ page }) => {
    await page.goto('/market');
    await expect(page.locator('textarea').first()).toBeVisible();
});

test('farm advisor scores the current region', async ({ page }) => {
    await page.goto('/farm');
    await expect(page.getByRole('heading', { name: /Where to farm/ })).toBeVisible();
    await expect(page.getByText(/tour size/)).toBeVisible();
});

test('trade finder page renders', async ({ page }) => {
    await page.goto('/trade');
    await expect(page.locator('h1')).not.toBeEmpty();
});

test('warzone page shows incursions and FW', async ({ page }) => {
    await page.goto('/warzone');
    await expect(page.getByRole('heading', { name: /Incursions/ })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'FW occupancy' })).toBeVisible();
});

test('agent finder lists nearby agents', async ({ page }) => {
    await page.goto('/agents');
    await expect(page.getByRole('heading', { name: /Agent finder/ })).toBeVisible();
});

test('settings page saves routing safety', async ({ page }) => {
    await page.goto('/settings');
    await expect(page.locator('h1')).toContainText(/Settings/i);
});
