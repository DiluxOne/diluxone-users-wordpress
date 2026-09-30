import { Page } from '@playwright/test';
import { test, expect, whoOn, opensDashboard, signInFrom, toTheHub, SiteHandle } from './support';
import { freshEmail } from '../support/api';
import { wp } from '../support/cli';
import { accountSection, adminUrl, challengeScreen, emailField, loginWay, openPanel, passwordForm, savePanel, signInWithPassword } from '../support/ui';
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

	test('“only the link” saved on the main site takes the password form off the sign-in every site sends to', async ({
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

			// Each site's door leads to the one sign-in page, which has lost
			// its password form for all of them.
			await toTheHub(guest, one, hub);
			await expect(emailField(guest), `from /${one.slug}/, the form`).toBeVisible();
			await expect(passwordForm(guest), `from /${one.slug}/, no password`).toHaveCount(0);
		}
	});
});

test.describe('Reports › Sessions is a report on this site', () => {
	test.use({ storageState: NETWORK_ADMIN_STATE });

	test('on /alpha/ it lists the members of /alpha/, and not somebody signed in from /beta/ only', async ({
		page,
		browser,
		hub,
		alpha,
		beta,
	}) => {
		const tag = `sess-${Date.now().toString(36)}`;
		const here = `${tag}-alpha@e2e.test`;
		const there = `${tag}-beta@e2e.test`;

		// Somebody who is a member of /beta/ only exists under "by invitation"
		// (or "whoever asks"); under "every site" everybody is on /alpha/ too.
		await hub.set({ diluxone_users_membership: 'invite' });

		await alpha.site.makeUser({ email: here, password: PASSWORD });
		await beta.site.makeUser({ email: there, password: PASSWORD });

		// Both signed in, each from their own site, each in their own browser.
		for (const [who, one] of [
			[here, alpha],
			[there, beta],
		] as const) {
			// Explicitly nobody: inside a describe that uses the admin's
			// session, a new context inherits it, and the sign-in shortcode
			// draws nothing for somebody already signed in.
			const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
			const person = await context.newPage();

			await signInFrom(person, one, hub, who, PASSWORD);
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

	/** Opens the box on the account's details that holds the photo form. */
	async function photoForm(page: Page) {
		const form = page.locator('form.diluxone-users-avatar__form');

		await openPanel(page, 'form.diluxone-users-avatar__form');

		return form;
	}

	/**
	 * Signs a member of both sites in from /alpha/ and uploads a photo on the
	 * account — the hub's, where the account lives.
	 */
	async function uploadFromAlpha(page: Page, hub: SiteHandle, alpha: SiteHandle, beta: SiteHandle) {
		const email = freshEmail('net-photo');
		const person = await alpha.site.makeUser({ email, password: PASSWORD });

		await beta.site.makeUser({ email, password: PASSWORD });

		await signInFrom(page, alpha, hub, email, PASSWORD);
		await page.goto(accountSection(hub.pages.account.url, 'details'));

		const form = await photoForm(page);

		await form.locator('input[name="diluxone_users_avatar_file"]').setInputFiles({
			name: 'me.png',
			mimeType: 'image/png',
			buffer: PIXEL,
		});
		await Promise.all([page.waitForURL(/diluxone-users=saved/), form.locator('button[type="submit"]').first().click()]);

		const attachment = Number((await hub.site.user(email, ['diluxone_users_avatar'])).fields.diluxone_users_avatar);

		expect(attachment, 'the photo became an attachment').toBeGreaterThan(0);

		const src = await page.locator('.diluxone-users-avatar__current img').getAttribute('src');

		expect(src, 'the photo is drawn on the account').toContain('/uploads/');

		return { email, person, attachment, src: src! };
	}

	test('uploaded on the account, it is the same photo in /beta/’s menu', async ({ page, hub, alpha, beta }) => {
		const { src } = await uploadFromAlpha(page, hub, alpha, beta);
		const menu = await beta.site.menu();

		await beta.set({ diluxone_users_menu_location: menu.location, diluxone_users_menu_style: 'avatar-name' });

		try {
			await page.goto(menu.url);

			// The same file. The address may start with /beta/: WordPress
			// builds a network's upload URLs from the content URL of the site
			// serving the page, and the network's rewrite rules send both to
			// the one file. What must match is the file.
			const file = (url: string | null) => (url ?? '').replace(/^.*\/wp-content\//, '');

			expect(
				file(await page.locator('img.diluxone-users-menu__avatar').getAttribute('src')),
				'the same picture on the next site of the network'
			).toBe(file(src));
		} finally {
			await beta.site.forgetMenu();
		}
	});

	test('removed on the account, it takes no file of /beta/ with it', async ({ page, hub, alpha, beta }) => {
		const { person, attachment } = await uploadFromAlpha(page, hub, alpha, beta);

		// /beta/ has its own media library, and in it a file of this person's
		// with the same number as the photo on the hub — which is what two
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
			await page.goto(accountSection(hub.pages.account.url, 'details'));

			const form = await photoForm(page);
			const remove = form.locator('button[name="diluxone_users_avatar_remove"]');

			await expect(remove, 'the account knows there is a photo to remove').toBeVisible();
			await Promise.all([page.waitForURL(/diluxone-users=saved/), remove.click()]);

			expect(
				wp(['post', 'list', '--post_type=attachment', '--post_status=any', `--post__in=${attachment}`, '--field=ID'], beta.url),
				'removing the photo on the hub deleted a file of /beta/'
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
	test('required of administrators, it is asked of an administrator of /alpha/ who signs in for /beta/ as a subscriber', async ({
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

		// The control: from /alpha/ the password is not enough.
		await signInFrom(page, alpha, hub, email, PASSWORD);
		await expect(challengeScreen(page)).toBeVisible();
		expect(await whoOn(page, alpha.url), 'no session on /alpha/ before the code').toBeNull();

		// From /beta/, where they are only a subscriber, the password is not
		// enough either: the session a sign-in opens is a cookie on `/`, the
		// whole network's, and it would open /alpha/'s dashboard. So "only
		// administrators" means an administrator of any site of theirs.
		await page.context().clearCookies();
		await signInFrom(page, beta, hub, email, PASSWORD);
		await expect(challengeScreen(page)).toBeVisible();
		expect(await whoOn(page, beta.url), 'no session anywhere before the code').toBeNull();

		expect(
			await opensDashboard(page, alpha.url),
			'a password typed for /beta/ opened /alpha/’s dashboard with no second step'
		).toBe(false);

		// A password posted to /beta/'s own wp-login.php — the emergency door,
		// which each site keeps — is WordPress's, and still meets the second
		// step, on the hub.
		await page.context().clearCookies();
		await page.goto(`${beta.url}wp-login.php?diluxone-users-admin=1`);
		await signInWithPassword(page, email, PASSWORD);
		await expect(challengeScreen(page)).toBeVisible();
		expect(page.url().startsWith(hub.url) && !page.url().startsWith(beta.url), 'asked on the hub').toBe(true);
		expect(await opensDashboard(page, alpha.url), 'the emergency door is no way round it').toBe(false);
	});
});
