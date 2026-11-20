const { test, expect } = require('@playwright/test');
const path = require('path');
const { signInAs, watchErrors } = require('./helpers');
const { seed } = require('./fixtures/seed');

/**
 * The parts of the entry form that are the same for every event are kept on
 * the profile; joining is then one short form shown a question at a time. The
 * steps and the certificate fields are switched in the browser, so only a
 * browser can check them: Next waits for the declaration, then the company is
 * shown to confirm, then photos, then payment; Previous goes back, and Join
 * appears on the last step only.
 */

/** A real 1x1 PNG: the server reads what a file is, not its name. */
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
const photos = n => Array.from({ length: n }, (_, i) => ({ name: `product${i + 1}.png`, mimeType: 'image/png', buffer: PNG }));
const PDF = name => ({ name, mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 ' + name) });

/** Past the company step, then five photos: on the payment step. */
async function toPayment(form) {
    const next = form.getByRole('button', { name: 'Next' });
    await form.getByLabel('I have checked these details and they are correct.').check();
    await next.click();
    await form.locator('#product_images').setInputFiles(photos(5));
    await next.click();
}

test('a participant fills in the profile first, then walks the join steps and sends', async ({ page }) => {
    const eventId = seed('join-setup.php').trim().split('\n').pop();
    const errors = watchErrors(page);

    await signInAs(page, 'E2E-TENUN');
    await page.goto(`/events/${eventId}`);

    // No profile yet: sent to fill it in, and no form to send.
    await expect(page.getByText('Complete your profile first')).toBeVisible();
    await expect(page.locator('#join-form')).toHaveCount(0);
    await page.getByRole('link', { name: 'Go to my profile' }).click();

    const profile = page.locator('[data-profile-form]');
    // Saved empty: every missing answer says so under itself, and nothing is sent.
    await page.getByRole('button', { name: 'Save information' }).click();
    await expect(profile.locator('[data-client-error="entrepreneur_category"]')).toHaveText('Choose one.');
    await expect(profile.locator('[data-client-error="oku"]')).toHaveText('Choose one.');
    await expect(profile.locator('[data-client-error="staff[0][phone]"]')).toHaveText('Fill in this field.');
    await expect(page).toHaveURL(/\/profile$/);
    await profile.getByLabel('Entrepreneur category').selectOption('Mikro, Kecil & Sederhana (MKS)');
    await profile.getByLabel('No', { exact: true }).first().check();
    await profile.getByLabel('Staff name', { exact: true }).fill('Siti Aminah');
    await profile.getByLabel('Mobile', { exact: true }).fill('012-345 6789');
    // More staff: a new empty row; one removed is not sent.
    await expect(profile.locator('[data-staff-remove]')).toBeHidden();
    const staffRow = (i) => profile.locator('[data-staff-row]').nth(i);
    await staffRow(0).getByRole('button', { name: 'Add staff' }).click();
    await profile.locator('#staff_1_name').fill('Ahmad');
    await profile.locator('#staff_1_phone').fill('019-111 2222');
    await staffRow(1).getByRole('button', { name: 'Add staff' }).click();
    await expect(profile.locator('#staff_2_name')).toHaveValue('');
    await staffRow(2).getByRole('button', { name: 'Remove this staff member' }).click();
    await expect(profile.locator('[data-staff-row]')).toHaveCount(2);
    await profile.getByLabel(/Workshop \/ factory address/).fill('Lot 5, Kampung Tenun');
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Save information' }).click()]);
    await expect(page.getByText('Information saved.')).toBeVisible();
    await expect(page.locator('#staff_1_name')).toHaveValue('Ahmad');

    await profile.getByLabel('Adiguru Kraf').check();
    await profile.locator('#recognition_file_adiguru').setInputFiles(PDF('adiguru.pdf'));

    // Yes to the classification certificate: its fields show, and are needed.
    const classNumber = profile.locator('#classification_number');
    await expect(classNumber).toBeHidden();
    await profile.locator('input[name=classification][value=yes]').check();
    await expect(classNumber).toBeVisible();
    await expect(profile.locator('#classification_file')).toHaveJSProperty('required', true);
    // No: they go, and are not sent.
    await profile.locator('input[name=classification][value=no]').check();
    await expect(classNumber).toBeHidden();
    await expect(classNumber).toBeDisabled();
    await profile.locator('input[name=classification][value=yes]').check();
    await classNumber.fill('PKT-123');
    await profile.locator('#classification_start').fill('2026-01-01');
    await profile.locator('#classification_end').fill('2030-12-31');
    await profile.locator('#classification_file').setInputFiles(PDF('cert.pdf'));

    // A tenun maker is asked about its own standard only.
    await expect(profile.getByText('Songket / Tenunan Standard entrepreneur')).toBeVisible();
    await expect(profile.getByText('Batik Standard entrepreneur')).toHaveCount(0);
    await profile.locator('input[name=quality][value=none]').check();
    await expect(profile.locator('#quality_number')).toBeHidden();

    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Save details' }).click()]);
    await expect(page.getByText('Details saved.')).toBeVisible();
    await expect(page.getByText('On file: cert.pdf')).toBeVisible();
    await expect(page.getByText('On file: adiguru.pdf')).toBeVisible();
    await expect(page.locator('#staff_1_name')).toHaveValue('Ahmad');
    await expect(page.locator('[data-profile-missing]')).toHaveCount(0);

    await page.goto(`/events/${eventId}`);
    const form = page.locator('#join-form');
    const next = form.getByRole('button', { name: 'Next' });
    const prev = form.getByRole('button', { name: 'Previous' });
    const join = page.getByRole('button', { name: 'Join this event' });
    const step = n => expect(form.getByText(`Step ${n} of 4`)).toBeVisible();

    // Declaration, the company, photos, payment.
    await step(1);
    await expect(prev).toBeDisabled();
    await expect(next).toBeDisabled();
    await expect(join).toBeHidden();

    // Ticked and unticked: Next follows the declaration.
    const declare = form.getByLabel(/I want to join this event, and I declare/);
    await declare.check();
    await expect(next).toBeEnabled();
    await declare.uncheck();
    await expect(next).toBeDisabled();
    await declare.check();
    await next.click();

    // The company as the profile has it, to confirm.
    await step(2);
    await expect(declare).toBeHidden();
    const details = form.locator('[data-company-details]');
    await expect(details.getByText('Adiguru Kraf')).toBeVisible();
    await expect(details.getByText(/PKT-123/)).toBeVisible();
    await expect(details.getByText('Lot 5, Kampung Tenun')).toBeVisible();
    // Not confirmed: Next stays on the details.
    await next.click();
    await step(2);
    await form.getByLabel('I have checked these details and they are correct.').check();
    await next.click();

    // Four photos are not a set; five go on.
    await step(3);
    await form.locator('#product_images').setInputFiles(photos(4));
    await next.click();
    await step(3);
    await expect(form.locator('#product_images')).toHaveJSProperty('validity.customError', true);
    await form.locator('#product_images').setInputFiles(photos(5));
    await next.click();

    await step(4);
    await expect(form.getByText('How did you pay?')).toBeVisible();
    await expect(next).toBeHidden();
    await expect(join).toBeVisible();

    await prev.click();
    await step(3);
    await expect(join).toBeHidden();
    await next.click();

    // "How did you pay?" is required, waiver included: Join with nothing picked
    // sends nothing, and a waiver with no category is held too.
    await join.click();
    await step(4);
    await expect(form.locator('input[name=payment_method]').first()).toHaveJSProperty('validity.valueMissing', true);
    await form.getByLabel('Fee waiver').check();
    await join.click();
    await expect(form.locator('input[name=waiver_category]').first()).toHaveJSProperty('validity.valueMissing', true);
    expect(await page.evaluate(() => document.querySelectorAll('.evd-msg-wait').length)).toBe(0);

    await form.getByLabel('Online transfer').check();
    await form.locator('#receipt').setInputFiles(PDF('slip.pdf'));
    // A second press while the first is on its way sends nothing: the first is
    // held back here so there is a moment to press again, as on a slow line.
    let posts = 0;
    await page.route(`**/events/${eventId}/join`, async route => {
        posts++;
        await new Promise(r => setTimeout(r, 500));
        await route.continue().catch(() => {});
    });
    await page.evaluate(() => {
        const button = document.querySelector('[data-join-submit]');
        button.click();
        setTimeout(() => button.click(), 100);
    });

    await expect(page.getByText('The office is reviewing your documents')).toBeVisible();
    await expect(page.getByText('already with the office')).toHaveCount(0);
    expect(posts).toBe(1);
    expect(errors).toEqual([]);
});

test('paying asks for the receipt only; a fee waiver asks for a category and documents', async ({ page }) => {
    const eventId = seed('join-setup.php').trim().split('\n').pop();
    const errors = watchErrors(page);

    await signInAs(page, 'E2E-LOGAM');
    await page.goto(`/events/${eventId}`);

    const form = page.locator('#join-form');
    await form.getByLabel(/I want to join this event, and I declare/).check();
    await form.getByRole('button', { name: 'Next' }).click();
    await toPayment(form);
    await expect(form.getByText('Step 4 of 4')).toBeVisible();

    // Paying: the receipt, and no supporting documents.
    await form.getByLabel('Cheque').check();
    await expect(form.locator('#receipt')).toBeVisible();
    await expect(form.locator('#documents')).toBeHidden();

    await form.getByLabel('Fee waiver').check();
    await expect(form.locator('#receipt')).toBeHidden();
    await expect(form.locator('#documents')).toBeVisible();
    await expect(form.getByText('Which waiver applies to you?')).toBeVisible();

    // A waiver needs its documents, whatever the category.
    await expect(form.locator('#documents')).toHaveJSProperty('required', true);
    await form.getByLabel('First time joining a promotion with Kraftangan Malaysia', { exact: true }).check();
    await expect(form.locator('#documents')).toHaveJSProperty('required', true);

    // Back to paying: the receipt returns and is required again.
    await form.getByLabel('Cash').check();
    await expect(form.locator('#receipt')).toBeVisible();
    await expect(form.locator('#receipt')).toHaveJSProperty('required', true);

    await form.getByLabel('Fee waiver').check();
    await form.locator('#documents').setInputFiles({ name: 'letter.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 letter') });
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Join this event' }).click()]);
    await expect(page.getByText('The office is reviewing your documents')).toBeVisible();
    expect(errors).toEqual([]);
});

test('Save draft keeps the answers so far and comes back on the same step', async ({ page }) => {
    const eventId = seed('join-setup.php').trim().split('\n').pop();
    const errors = watchErrors(page);

    await signInAs(page, 'E2E-KAYU');
    await page.goto(`/events/${eventId}`);

    const form = page.locator('#join-form');
    await form.getByLabel(/I want to join this event, and I declare/).check();
    await form.getByRole('button', { name: 'Next' }).click();
    await toPayment(form);

    // On the payment step, half done: nothing required is checked.
    await form.getByLabel('Cash').check();
    await Promise.all([page.waitForNavigation(), form.getByRole('button', { name: 'Save draft' }).click()]);

    await expect(page.getByText('Draft saved.')).toBeVisible();
    await expect(form.getByText('Step 4 of 4')).toBeVisible();
    await expect(form.getByLabel('Cash')).toBeChecked();

    // Later: back from the start, answers in place, and not sent.
    await page.goto(`/events/${eventId}`);
    await expect(page.getByText('You saved a draft on')).toBeVisible();
    await expect(form.getByText('Step 1 of 4')).toBeVisible();
    await expect(form.getByLabel('Cash')).toBeChecked();
    expect(errors).toEqual([]);
});

test('Join right after Save draft sends the request; a tick missing on another step is shown, not swallowed', async ({ page }) => {
    const eventId = seed('join-setup.php').trim().split('\n').pop();
    const errors = watchErrors(page);

    await signInAs(page, 'E2E-KAYU');
    await page.goto(`/events/${eventId}`);
    const form = page.locator('#join-form');
    const next = form.getByRole('button', { name: 'Next' });
    const join = page.getByRole('button', { name: 'Join this event' });
    const confirm = form.getByLabel('I have checked these details and they are correct.');

    await form.getByLabel(/I want to join this event, and I declare/).check();
    await next.click();
    await confirm.check();
    await next.click();
    await form.locator('#product_images').setInputFiles(photos(5));
    await next.click();
    await form.getByLabel('Online transfer').check();
    await form.locator('#receipt').setInputFiles(PDF('slip.pdf'));
    await Promise.all([page.waitForNavigation(), form.getByRole('button', { name: 'Save draft' }).click()]);
    await expect(page.getByText('Draft saved.')).toBeVisible();
    await expect(form.getByText('Step 4 of 4')).toBeVisible();

    // The ticks came back with the page. Untick one: Join opens its step.
    await expect(confirm).toBeChecked();
    await page.evaluate(() => { document.querySelector('input[name=details_confirmed]').checked = false; });
    await join.click();
    await expect(form.getByText('Step 2 of 4')).toBeVisible();
    await expect(page.getByText('Draft saved.')).toBeVisible();

    // Ticked again and back to the end: Join sends it to the office.
    await confirm.check();
    await next.click();
    await next.click();
    await Promise.all([page.waitForNavigation(), join.click()]);
    await expect(page.getByText('The office is reviewing your documents')).toBeVisible();
    expect(errors).toEqual([]);
});
