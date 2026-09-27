import { Page } from '@playwright/test';
import { test, expect, whoOn, opensDashboard, SiteHandle } from './support';
import { freshEmail } from '../support/api';
import { wp } from '../support/cli';
import { adminUrl, challengeScreen, loginWay, passwordForm, openWay, savePanel, signInWithPassword } from '../support/ui';
import { NETWORK_ADMIN_STATE } from '../../../playwright.network.config';

/**
 * What must not leak from one site of the network to the next.
 *
 * The plugin keeps its settings per site on purpose (includes/multisite.php
 * says why), and a person's account, their photo and their second step
 * belong to the network. Every test here stands on one of those two facts and
 * checks the other site did not notice.
 */

const PASSWORD = 'e2e-Network-1!';

/** The account area's details section on one site. */
const details = (one: SiteHandle) => `${one.pages.account.url.replace(/\/?$/, '/')}details/`;

/** A PNG of one teal pixel: small enough to inline, real enough for the media library. */
const PIXEL = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkaPhfDwAEmwH/8d3cZQAAAABJRU5ErkJggg==',
	'base64'
);

test.describe('Settings belong to the site they were saved on', () => {
	test.use({ storageState: NETWORK_ADMIN_STATE });

	test('“only the link” saved on /alpha/ leaves /beta/ with its password form', async ({ page, guest, alpha, beta }) => {
		await alpha.set({ diluxone_users_login_method: 'both' });
		await beta.set({ diluxone_users_login_method: 'both' });

		await page.goto(`${alpha.url.replace(/\/$/, '')}${adminUrl('diluxone-users-login', 'ways')}`);
		await loginWay(page, 'link').check();
		await loginWay(page, 'password').uncheck();
		await savePanel(page);

		expect((await alpha.site.getOptions(['diluxone_users_login_method'])).diluxone_users_login_method).toBe('link');
		expect(
			(await beta.site.getOptions(['diluxone_users_login_method'])).diluxone_users_login_method,
			'the save on /alpha/ reached /beta/'
		).toBe('both');

		await guest.goto(alpha.pages.login.url);
		await expect(passwordForm(guest)).toHaveCount(0);

		await guest.goto(beta.pages.login.url);
		await openWay(guest, 'password');
		await expect(passwordForm(guest)).toBeVisible();
	});
});

test.describe('Reports › Sessions is a report on this site', () => {
	test.use({ storageState: NETWORK_ADMIN_STATE });

	test('on /alpha/ it lists the members of /alpha/, and not somebody signed in on /beta/ only', async ({
		page,
		browser,
		alpha,
		beta,
	}) => {
		const tag = `sess-${Date.now().toString(36)}`;
		const here = `${tag}-alpha@e2e.test`;
		const there = `${tag}-beta@e2e.test`;

		await alpha.site.makeUser({ email: here, password: PASSWORD });
		await beta.site.makeUser({ email: there, password: PASSWORD });

		// Both signed in, each on their own site, each in their own browser.
		for (const [who, one] of [
			[here, alpha],
			[there, beta],
		] as const) {
			// Explicitly nobody: inside a describe that uses the admin's
			// session, a new context inherits it, and the sign-in shortcode
			// draws nothing for somebody already signed in.
			const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
			const person = await context.newPage();

			await person.goto(one.pages.login.url);
			await signInWithPassword(person, who, PASSWORD);
			await person.waitForLoadState('domcontentloaded');
			expect(await whoOn(person, one.url)).toBe(who);
			await context.close();
		}

		await page.goto(`${alpha.url.replace(/\/$/, '')}${adminUrl('diluxone-users-reports', 'sessions', { s: tag })}`);

		const mails = page.locator('.diluxone-users-list__mail');

		await expect(mails.filter({ hasText: here })).toHaveCount(1);
		await expect(mails.filter({ hasText: there }), 'a member of /beta/ only, on /alpha/’s report').toHaveCount(0);
	});
});

test.describe('Add New User on a site of the network', () => {
	test.use({ storageState: NETWORK_ADMIN_STATE });

	test('the e-mail is the username, and the account is made and made a member here', async ({ page, alpha }) => {
		const email = freshEmail('net-new');

		await page.goto(alpha.admin('user-new.php'));

		// The "Add New User" form, not "Add Existing User": the one with a
		// username field, which the plugin hides and fills from the e-mail.
		const form = page.locator('form#createuser');

		await form.locator('input[name="email"]').fill(email);

		// A super admin may skip the confirmation mail; without it the
		// account only exists once somebody clicks it, which is not what is
		// being tested here.
		const skip = form.locator('input[name="noconfirmation"]');

		if ((await skip.count()) > 0) {
			await skip.check();
		}

		await Promise.all([page.waitForLoadState('domcontentloaded'), form.locator('#createusersub').click()]);

		// WordPress's own error box, if the network refused the username.
		await expect(page.locator('#message.error, .notice-error, .error'), 'the network refused the account').toHaveCount(0);

		const made = await alpha.site.user(email);

		expect(made.exists, 'the account exists').toBe(true);
		expect(made.member, 'and belongs to /alpha/').toBe(true);
	});
});

test.describe('A photo belongs to the person, and the person to the network', () => {
	test.beforeEach(async ({ alpha, beta }) => {
		for (const one of [alpha, beta]) {
			await one.set({ diluxone_users_avatar_upload: 1, diluxone_users_2fa_mode: 'off' });
		}
	});

	/** Signs a member of both sites in on /alpha/ and uploads a photo there. */
	async function uploadOnAlpha(page: Page, alpha: SiteHandle, beta: SiteHandle) {
		const email = freshEmail('net-photo');
		const person = await alpha.site.makeUser({ email, password: PASSWORD });

		await beta.site.makeUser({ email, password: PASSWORD });

		await page.goto(alpha.pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await page.waitForLoadState('domcontentloaded');
		await page.goto(details(alpha));

		const form = page.locator('form.diluxone-users-avatar__form');

		await form.locator('input[name="diluxone_users_avatar_file"]').setInputFiles({
			name: 'me.png',
			mimeType: 'image/png',
			buffer: PIXEL,
		});
		await Promise.all([page.waitForURL(/diluxone-users=saved/), form.locator('button[type="submit"]').first().click()]);

		const attachment = Number((await alpha.site.user(email, ['diluxone_users_avatar'])).fields.diluxone_users_avatar);

		expect(attachment, 'the photo became an attachment').toBeGreaterThan(0);

		const src = await page.locator('.diluxone-users-avatar__current img').getAttribute('src');

		expect(src, 'the photo is drawn on /alpha/').toContain('/uploads/');

		return { email, person, attachment, src: src! };
	}

	test('uploaded on /alpha/, it is the same photo on /beta/', async ({ page, alpha, beta }) => {
		const { src } = await uploadOnAlpha(page, alpha, beta);

		await page.goto(details(beta));

		expect(
			await page.locator('.diluxone-users-avatar__current img').getAttribute('src'),
			'the same picture on the next site of the network'
		).toBe(src);
	});

	test('removed on /beta/, it takes no file of /beta/ with it', async ({ page, alpha, beta }) => {
		const { person, attachment } = await uploadOnAlpha(page, alpha, beta);

		// /beta/ has its own media library, and in it a file of this person's
		// with the same number as the photo on /alpha/ — which is what two
		// auto-increment tables produce sooner or later on any network. It is
		// the file a careless "delete the photo" deletes.
		const own = Number(
			wp(
				[
					'post',
					'create',
					'--post_type=attachment',
					'--post_status=inherit',
					'--post_title=Their own document on beta',
					`--post_author=${person.id}`,
					`--import_id=${attachment}`,
					'--porcelain',
				],
				beta.url
			)
		);

		expect(own, '/beta/ has a file with the same id').toBe(attachment);

		try {
			await page.goto(details(beta));

			const remove = page.locator('form.diluxone-users-avatar__form button[name="diluxone_users_avatar_remove"]');

			await expect(remove, '/beta/ knows there is a photo to remove').toBeVisible();
			await Promise.all([page.waitForURL(/diluxone-users=saved/), remove.click()]);

			expect(
				wp(['post', 'list', '--post_type=attachment', '--post_status=any', `--post__in=${attachment}`, '--field=ID'], beta.url),
				'removing the photo on /beta/ deleted a file of /beta/'
			).toBe(String(attachment));
		} finally {
			// Gone already when the assertion above failed; that is the finding,
			// and a cleanup that throws would hide it behind its own error.
			try {
				wp(['post', 'delete', String(attachment), '--force'], beta.url);
			} catch {
				// Nothing left to clean.
			}
		}
	});
});

test.describe('The second step is asked by the site that asks for it', () => {
	test('an administrator of /alpha/ who signs in on /beta/ does not reach /alpha/’s dashboard without it', async ({
		page,
		alpha,
		beta,
	}) => {
		await alpha.set({
			diluxone_users_2fa_mode: 'required',
			diluxone_users_2fa_methods: ['email'],
			diluxone_users_2fa_scope: 'some',
			diluxone_users_2fa_roles: ['administrator'],
		});
		await beta.set({ diluxone_users_2fa_mode: 'off', diluxone_users_login_method: 'both' });

		const email = freshEmail('net-2fa');

		await alpha.site.makeUser({ email, password: PASSWORD, role: 'administrator' });
		await beta.site.makeUser({ email, password: PASSWORD, role: 'subscriber' });

		// The control: on /alpha/ itself the password is not enough.
		await page.goto(alpha.pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expect(challengeScreen(page)).toBeVisible();
		expect(await whoOn(page, alpha.url), 'no session on /alpha/ before the code').toBeNull();

		// On /beta/, where nobody is asked, the password is.
		await page.context().clearCookies();
		await page.goto(beta.pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await page.waitForLoadState('domcontentloaded');
		expect(await whoOn(page, beta.url)).toBe(email);

		// And that session is a cookie on `/` — the whole network's. What it
		// must not be is an administrator's session on the site that demands
		// a second step from its administrators.
		expect(
			await opensDashboard(page, alpha.url),
			'a password typed on /beta/ opened /alpha/’s dashboard with no second step'
		).toBe(false);
	});
});

