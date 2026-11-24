const { test, expect } = require('@playwright/test');
const { signInAs, strayAlerts, watchErrors } = require('./helpers');

// An admin opens the portal as one of their participants and comes back.
// In a real browser, because the two guards share ONE session cookie: Pest
// keeps both guards in memory between requests, so a session that drops one
// guard on the way, or a Sign out that ends the admin too, only shows here.

test.afterEach(async ({ page }) => {
    expect(strayAlerts(page), 'an alert was accepted where a confirm was expected').toEqual([]);
});

const submitting = (page, action) => Promise.all([page.waitForNavigation(), action]);

test('the zone admin runs the portal as a participant, changes something, and returns still an admin', async ({ page }) => {
    const errors = watchErrors(page);
    const district = `Daerah ${Date.now()}`;

    await signInAs(page, 'e2e-admin');
    await page.goto('/admin/companies');
    await submitting(page, page.locator('tr', { hasText: 'Tenun E2E' }).getByTitle('Edit').click());
    const companyPage = page.url();

    await submitting(page, page.getByRole('button', { name: 'Log in as this participant' }).click());

    // The participant's portal, marked as the admin's visit.
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByText('You are viewing undilot as')).toBeVisible();
    await expect(page.getByText('Everything you do is recorded under E2E Admin')).toBeVisible();

    // The session holds across pages, and a write goes through.
    await page.goto('/profile');
    await expect(page.getByText('You are viewing undilot as')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Change password' })).toHaveCount(0);
    await page.locator('#acc_district').fill(district);
    await submitting(page, page.getByRole('button', { name: 'Save account' }).click());
    await expect(page.locator('#acc_district')).toHaveValue(district);

    // The password is the participant's alone: back to the profile, with the reason.
    await page.goto('/password');
    await expect(page).toHaveURL(/\/profile$/);
    await expect(page.getByText('Only the participant can change their password.')).toBeVisible();
    await expect(page.getByText('You are viewing undilot as')).toBeVisible();

    // Back: on the company page, still signed in as the admin, the portal closed.
    await page.goto('/dashboard');
    await submitting(page, page.getByRole('button', { name: 'Return to admin' }).click());
    expect(page.url()).toBe(companyPage);
    await expect(page.getByText('Back to your admin account.')).toBeVisible();
    await expect(page.locator('#district')).toHaveValue(district);

    await page.goto('/admin/dashboard');
    await expect(page).toHaveURL(/\/admin\/dashboard$/);

    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/);

    expect(errors).toEqual([]);
});
