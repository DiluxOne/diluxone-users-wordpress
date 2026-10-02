import type { Page } from '@playwright/test';
import { test, expect, expectSignedIn } from '../support/fixtures';
import { freshEmail, linkIn, waitForMail } from '../support/api';
import { accountSection, registerScreen, resetScreen, savePanel, signInWithPassword } from '../support/ui';
import { accessTab, control, expectPill, notNow, rail, summaryRow } from '../support/admin-access';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Access › The sign-in page, every control driven through the tab.
 *
 * Which page holds the form, what becomes of wp-login.php and where a new
 * password is typed. Each answer is picked on the tab, saved, read back, and
 * then followed from the public side: the address wp-login.php ends up at,
 * the link a registration page points to, the screen a reset e-mail opens.
 */

test.use({ storageState: ADMIN_STATE });

const PASSWORD = 'e2e-Page-1!';

/** WordPress's own lost-password form, and the link its e-mail carries. */
async function askForAReset(page: Page, site: import('../support/api').Site, email: string): Promise<string> {
	await page.goto('/wp-login.php?action=lostpassword');
	await page.locator('input[name="user_login"]').fill(email);
	await page.locator('#wp-submit').click();

	return linkIn(await waitForMail(site, email, { subject: /contrase|password/i }));
}

test.describe('Access › The sign-in page', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep(['diluxone_users_login_page', 'diluxone_users_wp_screens', 'diluxone_users_lost_password', 'diluxone_users_rewrite_version']);
	});

	test('the page chosen in the drop-down is where wp-login.php sends people, and “None” gives them wp-login.php back', async ({ page, guest, site, pages, options }) => {
		await options.set({ diluxone_users_login_method: 'both', diluxone_users_register_form: 1 });

		const other = await site.page('admin-access-login2', '<!-- wp:shortcode -->[diluxone_users_login]<!-- /wp:shortcode -->');

		try {
			await page.goto(accessTab('page'));
			await page.locator('#diluxone_users_login_page').selectOption(String(other.id));
			await control(page, 'diluxone_users_wp_screens', 'mine').check({ force: true });
			await savePanel(page);

			expect(Number((await site.getOptions(['diluxone_users_login_page'])).diluxone_users_login_page)).toBe(other.id);
			await page.reload();
			await expect(page.locator('#diluxone_users_login_page')).toHaveValue(String(other.id));

			// wp-login.php lands on the new page, and the new page draws the form.
			await guest.goto('/wp-login.php');
			expect(new URL(guest.url()).pathname).toBe(new URL(other.url).pathname);
			await expect(guest.locator('input[name="diluxone_users_email"]')).toBeVisible();

			// The site's other pages point at it.
			await guest.goto(pages.register.url);
			await expect(registerScreen(guest).locator(`a[href*="${new URL(other.url).pathname}"]`).first()).toBeAttached();

			// The Summary names it, with the way to it.
			await page.goto(accessTab('summary'));
			await expectPill(summaryRow(page, 'tab=page'), 'active');
			await expect(summaryRow(page, 'tab=page').locator(`a[href*="${new URL(other.url).pathname}"]`)).toHaveCount(1);

			// "None": nothing to send anybody to.
			await page.goto(accessTab('page'));
			await page.locator('#diluxone_users_login_page').selectOption('0');
			await savePanel(page);

			expect(Number((await site.getOptions(['diluxone_users_login_page'])).diluxone_users_login_page)).toBe(0);

			// The tab says the choice below waits for a page, and offers to make one.
			await expect(notNow(page).first()).toBeVisible();
			await expect(page.locator('a.button[href*="action=diluxone_users_create_page"][href*="diluxone_users_login_page"]')).toHaveCount(1);

			await guest.goto('/wp-login.php');
			expect(new URL(guest.url()).pathname, 'with no page, wp-login.php is the sign-in screen').toBe('/wp-login.php');
			await expect(guest.locator('#loginform')).toBeVisible();

			await page.goto(accessTab('summary'));
			await expectPill(summaryRow(page, 'tab=page'), 'pending');
		} finally {
			await site.forgetPage('admin-access-login2');
		}
	});

	test('“only while the link is the only way in”, chosen on the tab: wp-login.php is left alone with a password and taken over without one', async ({ page, guest, site, pages, options }) => {
		await options.set({ diluxone_users_login_method: 'both', diluxone_users_wp_screens: 'mine' });

		await page.goto(accessTab('page'));
		await control(page, 'diluxone_users_wp_screens', 'auto').check({ force: true });
		await savePanel(page);

		expect((await site.getOptions(['diluxone_users_wp_screens'])).diluxone_users_wp_screens).toBe('auto');
		await page.reload();
		await expect(control(page, 'diluxone_users_wp_screens', 'auto')).toBeChecked();

		await guest.goto('/wp-login.php');
		expect(new URL(guest.url()).pathname).toBe('/wp-login.php');
		await expect(guest.locator('#loginform')).toBeVisible();

		await options.set({ diluxone_users_login_method: 'link' });
		await guest.goto('/wp-login.php');
		expect(new URL(guest.url()).pathname).toBe(new URL(pages.login.url).pathname);
	});

	test('“a second screen” sent by hand on a site with no password is stored as the answer in force', async ({ page, site, options }) => {
		await options.set({ diluxone_users_login_method: 'link', diluxone_users_wp_screens: 'mine' });

		await page.goto(accessTab('page'));

		const wp = control(page, 'diluxone_users_wp_screens', 'wp');

		await expect(wp).toBeDisabled();

		// Freed in the inspector and sent.
		await wp.evaluate((radio: HTMLInputElement) => {
			radio.disabled = false;
			radio.checked = true;
		});
		await savePanel(page);

		expect((await site.getOptions(['diluxone_users_wp_screens'])).diluxone_users_wp_screens).toBe('auto');
	});

	for (const row of [
		{ answer: 'wp', lands: 'wp-login.php' },
		{ answer: 'site', lands: 'the sign-in page' },
	] as const) {
		test(`a forgotten password, “${row.answer}” chosen on the tab: the reset e-mail opens ${row.lands}`, async ({ page, guest, site, pages, options }) => {
			await options.set({ diluxone_users_login_method: 'both', diluxone_users_lost_password: row.answer === 'wp' ? 'site' : 'wp' });

			await page.goto(accessTab('page'));
			await control(page, 'diluxone_users_lost_password', row.answer).check({ force: true });
			await savePanel(page);

			expect((await site.getOptions(['diluxone_users_lost_password'])).diluxone_users_lost_password).toBe(row.answer);
			await page.reload();
			await expect(control(page, 'diluxone_users_lost_password', row.answer)).toBeChecked();

			const email = freshEmail(`lost-${row.answer}`);

			await site.makeUser({ email, password: PASSWORD });
			await guest.goto(await askForAReset(guest, site, email));

			if (row.answer === 'wp') {
				expect(new URL(guest.url()).pathname).toBe('/wp-login.php');
				await expect(guest.locator('#resetpassform')).toBeVisible();
			} else {
				expect(guest.url()).toContain(pages.login.url);
				await expect(resetScreen(guest)).toContainText(email);
			}
		});
	}

	test('a forgotten password, “nowhere” chosen on the tab: “I forgot” leads to the sign-in page and nothing is reset', async ({ page, guest, site, pages, options }) => {
		await options.set({ diluxone_users_login_method: 'both', diluxone_users_lost_password: 'wp', diluxone_users_wp_screens: 'mine' });

		await guest.goto(pages.login.url);
		await expect(guest.locator('a[href*="action=lostpassword"]'), 'before the save, the way to a reset').toBeVisible();

		await page.goto(accessTab('page'));
		await control(page, 'diluxone_users_lost_password', 'link').check({ force: true });
		await savePanel(page);

		expect((await site.getOptions(['diluxone_users_lost_password'])).diluxone_users_lost_password).toBe('link');

		await guest.goto(pages.login.url);
		await expect(guest.locator('a[href*="action=lostpassword"]'), 'the way to a reset is still offered').toHaveCount(0);

		const email = freshEmail('lost-link');

		await site.makeUser({ email, password: PASSWORD });
		await guest.goto('/wp-login.php?action=lostpassword');
		expect(new URL(guest.url()).pathname).toBe(new URL(pages.login.url).pathname);
		expect(await site.mail(email)).toEqual([]);
	});

	test('the notes beside the answers follow the page and the password, and the rail the look of wp-login.php', async ({ page, pages, options }) => {
		await options.set({ diluxone_users_login_page: 0, diluxone_users_login_method: 'both', diluxone_users_wp_login_brand: 0 });

		await page.goto(accessTab('page'));
		// No page: the choice waits for one, and so does "this site's screen" for a reset.
		await expect(notNow(page)).toHaveCount(1);
		await expectPill(page.locator('label.du-choice').filter({ has: control(page, 'diluxone_users_lost_password', 'site') }), 'pending');
		// wp-login.php is the only screen: the rail says how it looks — plain.
		await expectPill(rail(page).locator('.du-note').filter({ has: page.locator('.du-note__title .diluxone-users-state') }), 'off');

		await options.set({ diluxone_users_wp_login_brand: 1 });
		await page.reload();
		await expectPill(rail(page).locator('.du-note').filter({ has: page.locator('.du-note__title .diluxone-users-state') }), 'active');

		// A page, and no password: the note points to Ways in, the reset
		// waits on a password too, and wp-login.php is nobody's screen, so the
		// rail says nothing about its look.
		await options.set({ diluxone_users_login_page: pages.login.id, diluxone_users_login_method: 'link', diluxone_users_wp_screens: 'auto' });
		await page.reload();
		await expect(notNow(page).filter({ has: page.locator('a[href*="tab=ways"]') })).toHaveCount(1);
		await expect(notNow(page)).toHaveCount(2);
		await expect(page.locator('label.du-choice').filter({ has: control(page, 'diluxone_users_lost_password', 'site') }).locator('.diluxone-users-state')).toHaveCount(0);
		await expect(rail(page).locator('.du-note__title .diluxone-users-state')).toHaveCount(0);
	});
});

test.describe('Create the page', () => {
	test('for the sign-in page: made, chosen, announced, and where wp-login.php sends people', async ({ page, guest, site, options }) => {
		await options.set({ diluxone_users_login_page: 0, diluxone_users_login_method: 'both', diluxone_users_wp_screens: 'mine' });

		await page.goto(accessTab('page'));

		const create = page.locator('a.button[href*="action=diluxone_users_create_page"][href*="diluxone_users_login_page"]');

		await Promise.all([page.waitForURL(/diluxone_users_created=\d+/), create.click()]);

		const id = Number(new URL(page.url()).searchParams.get('diluxone_users_created'));

		try {
			expect(new URL(page.url()).searchParams.get('tab'), 'back on the tab the button was on').toBe('page');
			expect(Number((await site.getOptions(['diluxone_users_login_page'])).diluxone_users_login_page)).toBe(id);
			await expect(page.locator('.notice-success a')).toHaveCount(1);
			await expect(page.locator('#diluxone_users_login_page')).toHaveValue(String(id));
			await expect(create).toHaveCount(0);

			const made = (await page.locator('.notice-success a').getAttribute('href'))!;

			await guest.goto(made);
			await expect(guest.locator('input[name="diluxone_users_email"]')).toBeVisible();

			await guest.goto('/wp-login.php');
			expect(new URL(guest.url()).pathname).toBe(new URL(made).pathname);
		} finally {
			await site.forgetPageId(id);
		}
	});

	test('a page role the plugin does not have is refused, and nothing is made', async ({ page, site, options }) => {
		await options.set({ diluxone_users_login_page: 0 });

		await page.goto(accessTab('page'));

		const href = (await page.locator('a.button[href*="action=diluxone_users_create_page"]').first().getAttribute('href'))!;
		const pagesBefore = await page.request.get('/wp-admin/edit.php?post_type=page&post_status=all').then((r) => r.text());
		const countBefore = (pagesBefore.match(/<tr id="post-\d+"/g) ?? []).length;

		const answer = await page.goto(href.replace('diluxone_users_login_page', 'diluxone_users_foo'));

		expect(answer?.status()).toBe(403);
		expect(Number((await site.getOptions(['diluxone_users_login_page'])).diluxone_users_login_page)).toBe(0);

		const pagesAfter = await page.request.get('/wp-admin/edit.php?post_type=page&post_status=all').then((r) => r.text());

		expect((pagesAfter.match(/<tr id="post-\d+"/g) ?? []).length, 'a page was made anyway').toBe(countBefore);
	});

	/*
	 * A new account page is new addresses — one per section — and they work
	 * the moment the page exists, not after somebody visits Permalinks.
	 */
	test('for the account page: its sections answer at their own addresses at once', async ({ page, guest, site, options }) => {
		await options.set({ diluxone_users_account_page: 0 });
		await options.keep(['diluxone_users_rewrite_version']);

		await page.goto('/wp-admin/admin.php?page=diluxone-users-account&tab=page');

		const create = page.locator('a.button[href*="action=diluxone_users_create_page"][href*="diluxone_users_account_page"]');

		await Promise.all([page.waitForURL(/diluxone_users_created=\d+/), create.click()]);

		const id = Number(new URL(page.url()).searchParams.get('diluxone_users_created'));

		try {
			const made = (await page.locator('.notice-success a').getAttribute('href'))!;

			expect(made, 'a pretty address, not ?page_id=').not.toContain('page_id=');

			const email = freshEmail('created-account');

			await site.makeUser({ email, password: PASSWORD });
			await guest.goto('/wp-login.php');
			await signInWithPassword(guest, email, PASSWORD);
			await expectSignedIn(guest, email);

			const answer = await guest.goto(accountSection(made, 'security'));

			expect(answer?.status(), 'the section’s address is a 404').toBe(200);
			await expect(guest.locator('.diluxone-users-account')).toBeVisible();
		} finally {
			await site.forgetPageId(id);
		}
	});
});
