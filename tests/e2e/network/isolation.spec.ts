import { Page } from '@playwright/test';
import { test, expect, whoOn, opensDashboard, pageWith, SiteHandle } from './support';
import { freshEmail } from '../support/api';
import { wp } from '../support/cli';
import { adminUrl, challengeScreen, emailField, loginWay, passwordForm, savePanel, signInWithPassword } from '../support/ui';
import { NETWORK_ADMIN_STATE } from '../../../playwright.network.config';

/**
 * What a site of the network keeps to itself, and what it shares.
 *
 * The rules about people are the network's and the screens they sign in on
 * are the main site's, so a setting changed there is changed everywhere at
 * once (includes/multisite.php says why). A person's account, their photo and
 * their second step belong to the network. What each site keeps is its own:
 * its members, its reports, its media library. Every test here stands on one
 * of those facts and checks the other side of it.
 */

const PASSWORD = 'e2e-Network-1!';

/** A PNG of one teal pixel: small enough to inline, real enough for the media library. */
const PIXEL = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkaPhfDwAEmwH/8d3cZQAAAABJRU5ErkJggg==',
	'base64'
);

test.describe('The main site’s sign-in settings are every site’s', () => {
	test.use({ storageState: NETWORK_ADMIN_STATE });

	test('“only the link” saved on the main site takes the password form off /alpha/ and /beta/ alike', async ({
		page,
		guest,
		hub,
		alpha,
		beta,
	}) => {
		await hub.set({ diluxone_users_login_method: 'both' });

		await page.goto(`${hub.url.replace(/\/$/, '')}${adminUrl('diluxone-users-login', 'ways')}`);
		await loginWay(page, 'link').check();
		await loginWay(page, 'password').uncheck();
		await savePanel(page);

		for (const one of [alpha, beta]) {
			expect(
				(await one.site.getOptions(['diluxone_users_login_method'])).diluxone_users_login_method,
				`/${one.slug}/ reads the main site’s answer`
			).toBe('link');

			await guest.goto(one.pages.login.url);
			await expect(emailField(guest), `/${one.slug}/ still draws the form`).toBeVisible();
			await expect(passwordForm(guest), `no password on /${one.slug}/`).toHaveCount(0);
		}
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

	test('WordPress’s own username stays, and the account is made and made a member here', async ({ page, alpha }) => {
		const email = freshEmail('net-new');

		await page.goto(alpha.admin('user-new.php'));

		// The "Add New User" form, not "Add Existing User". On a single site
		// the plugin hides the username and fills it from the e-mail; on a
		// network a username may only hold lowercase letters and numbers, so
		// an address is refused as one and the field is left to WordPress.
		const form = page.locator('form#createuser');
		const login = form.locator('input[name="user_login"]');

		await expect(login, 'the username is asked for on a network').toBeVisible();
		await login.fill(`netnew${Date.now()}`);
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
	test.beforeEach(async ({ hub }) => {
		await hub.set({ diluxone_users_avatar_upload: 1, diluxone_users_2fa_mode: 'off' });
	});

	/**
	 * Signs a member of both sites in on /alpha/ and uploads a photo there, on
	 * a page of /alpha/ that draws the photo piece of the account.
	 */
	async function uploadOnAlpha(page: Page, alpha: SiteHandle, beta: SiteHandle) {
		const email = freshEmail('net-photo');
		const person = await alpha.site.makeUser({ email, password: PASSWORD });

		await beta.site.makeUser({ email, password: PASSWORD });

		await page.goto(alpha.pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await page.waitForLoadState('domcontentloaded');
		await page.goto(pageWith(alpha, 'diluxone_users_avatar'));

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

		await page.goto(pageWith(beta, 'diluxone_users_avatar'));

		// The same file. The address may start with /beta/ rather than
		// /alpha/: WordPress builds a network's upload URLs from the content
		// URL of the site serving the page, and the network's rewrite rules
		// send both to the one file. What must match is the file.
		const file = (url: string | null) => (url ?? '').replace(/^.*\/wp-content\//, '');

		expect(
			file(await page.locator('.diluxone-users-avatar__current img').getAttribute('src')),
			'the same picture on the next site of the network'
		).toBe(file(src));
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
			await page.goto(pageWith(beta, 'diluxone_users_avatar'));

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

test.describe('The second step is the network’s', () => {
	test('required of administrators, it is asked of an administrator of /alpha/ who signs in on /beta/ as a subscriber', async ({
		page,
		hub,
		alpha,
		beta,
	}) => {
		await hub.set({
			diluxone_users_2fa_mode: 'required',
			diluxone_users_2fa_methods: ['email'],
			diluxone_users_2fa_scope: 'some',
			diluxone_users_2fa_roles: ['administrator'],
			diluxone_users_login_method: 'both',
		});

		const email = freshEmail('net-2fa');

		await alpha.site.makeUser({ email, password: PASSWORD, role: 'administrator' });
		await beta.site.makeUser({ email, password: PASSWORD, role: 'subscriber' });

		// The control: on /alpha/ itself the password is not enough.
		await page.goto(alpha.pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expect(challengeScreen(page)).toBeVisible();
		expect(await whoOn(page, alpha.url), 'no session on /alpha/ before the code').toBeNull();

		// On /beta/, where they are only a subscriber, the password is not
		// enough either: the session a sign-in opens is a cookie on `/`, the
		// whole network's, and it would open /alpha/'s dashboard. So "only
		// administrators" means an administrator of any site of theirs.
		await page.context().clearCookies();
		await page.goto(beta.pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expect(challengeScreen(page)).toBeVisible();
		expect(await whoOn(page, beta.url), 'no session anywhere before the code').toBeNull();

		expect(
			await opensDashboard(page, alpha.url),
			'a password typed on /beta/ opened /alpha/’s dashboard with no second step'
		).toBe(false);
	});
});
