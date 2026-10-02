import { test, expect, whoOn, signInFrom, hubDoor, toTheHub } from './support';
import { freshEmail, linkIn, waitForMail } from '../support/api';
import { wp } from '../support/cli';
import { fillCredentials, navigated, signInWithPassword, signOut } from '../support/ui';
import { MAPPED_HOST, NETWORK_URL } from '../../../playwright.network.config';
import { throwawaySite, userMeta } from './hub-support';

/**
 * The hub's routing beyond the doors the other specs walk: a forgotten
 * password asked for from /beta/, the way out, the doors WordPress itself
 * prints, the shortcodes for somebody already signed in, the admin bar and
 * the menu, WordPress's own sign-up of a network, and a site on a domain of
 * its own, where a session opened on the hub would never arrive.
 */

const OLD = 'e2e-HubOld-1!';
const NEW = 'e2e-HubNew-2!';

test.describe('A forgotten password, from /beta/', () => {
	test('the request is the hub’s, the link mailed opens WordPress’s own screen, and the new password signs in from /beta/', async ({
		page,
		hub,
		alpha,
		beta,
	}) => {
		await hub.set({ diluxone_users_lost_password: 'wp', diluxone_users_login_method: 'both' });

		const email = freshEmail('hub-reset');

		await alpha.site.makeUser({ email, password: OLD });

		// WordPress's own address for it, on /beta/: the hub's.
		expect(wp(['eval', 'echo wp_lostpassword_url();'], beta.url), 'wp_lostpassword_url() on /beta/').not.toContain('/beta/');

		await page.goto(`${beta.url}wp-login.php?action=lostpassword`);
		expect(new URL(page.url()).pathname, 'sent to the hub').not.toContain('/beta/');

		await page.locator('input[name="user_login"]').fill(email);
		await navigated(page, () => page.locator('#wp-submit').click());

		const link = linkIn(await waitForMail(hub.site, email, { subject: /password/i }));

		expect(link, 'the link is the main site’s').toContain(`${NETWORK_URL}/wp-login.php`);

		await page.goto(link);
		await expect(page.locator('#resetpassform')).toBeVisible();

		const pass1 = page.locator('#pass1');

		// WordPress's script puts a generated password in first: let it, then type over it.
		await expect(pass1).not.toHaveValue('');
		await pass1.fill(NEW);
		await page.locator('#pass2').evaluate((field: HTMLInputElement, value) => {
			field.value = value;
		}, NEW);
		await navigated(page, () => page.locator('#wp-submit').click());
		await expect(page.locator('#resetpassform')).toHaveCount(0);

		// The old one is gone, the new one opens /beta/.
		await signOut(page);
		await toTheHub(page, beta, hub);
		await signInWithPassword(page, email, OLD);
		expect(await whoOn(page, beta.url), 'the old password').toBeNull();

		await signOut(page);
		await signInFrom(page, beta, hub, email, NEW);
		expect(await whoOn(page, beta.url), 'the new password, from /beta/').toBe(email);
	});

	test('a reset link opened on /beta/ is answered on /beta/, not sent to the hub', async ({ guest, beta }) => {
		const email = freshEmail('hub-rp-local');

		await beta.site.makeUser({ email, password: OLD });

		const key = wp(['eval', `echo get_password_reset_key( get_user_by( 'email', '${email}' ) );`], beta.url);
		const login = (await beta.site.user(email)).login as string;

		await guest.goto(`${beta.url}wp-login.php?action=rp&key=${encodeURIComponent(key)}&login=${encodeURIComponent(login)}`);

		expect(new URL(guest.url()).pathname, `stays on /beta/: ${guest.url()}`).toBe('/beta/wp-login.php');
		await expect(guest.locator('#resetpassform'), 'WordPress’s own screen, on /beta/').toBeVisible();
	});
});

test.describe('WordPress’s own doors on /beta/', () => {
	test('registration and a session to be confirmed again go to the hub and keep what they asked for; the interim sign-in stays', async ({
		guest,
		hub,
		beta,
		network,
	}) => {
		await network.set('registration', 'user');
		await hub.set({ diluxone_users_login_register: 1, diluxone_users_register_form: 0 });

		// With the hub's own form closed, the hub's wp-login.php, which on a
		// network is WordPress's wp-signup.php.
		await guest.goto(`${beta.url}wp-login.php?action=register`);
		expect(new URL(guest.url()).pathname, 'registration: the hub’s').not.toContain('/beta/');
		expect(guest.url(), 'still a registration').toMatch(/action=register|wp-signup\.php/);

		await guest.goto(`${beta.url}wp-login.php?reauth=1&redirect_to=${encodeURIComponent(`${beta.url}wp-admin/`)}`);
		expect(new URL(guest.url()).pathname, 'reauth: the hub’s').not.toContain('/beta/');
		expect(new URL(guest.url()).searchParams.get('reauth'), 'and still a reauth').toBe('1');

		await guest.goto(`${beta.url}wp-login.php?interim-login=1`);
		expect(new URL(guest.url()).pathname, 'the interim sign-in of the dashboard stays where the dashboard is').toBe('/beta/wp-login.php');
	});

	test('wp_registration_url() on /beta/ is the hub’s registration page when it is open, the hub’s wp-login.php when it is not', async ({ hub, beta, network }) => {
		await network.set('registration', 'user');
		await hub.set({ diluxone_users_login_register: 1, diluxone_users_register_form: 1 });

		const open = wp(['eval', 'echo wp_registration_url();'], beta.url);

		expect(open, 'the hub’s page').toContain(hub.pages.register.url.replace(/\/$/, ''));

		await hub.set({ diluxone_users_register_form: 0 });

		const closed = wp(['eval', 'echo wp_registration_url();'], beta.url);

		expect(closed, 'not /beta/’s').not.toContain('/beta/');
		expect(closed).toContain('wp-login.php');
		expect(closed).toContain('action=register');
	});

	test('signing out on /beta/ ends the session on every site of the network', async ({ page, hub, beta }) => {
		const email = freshEmail('hub-logout');

		await beta.site.makeUser({ email, password: OLD });
		await signInFrom(page, beta, hub, email, OLD);
		expect(await whoOn(page, beta.url)).toBe(email);

		await page.goto(`${beta.url}wp-login.php?action=logout`);
		expect(new URL(page.url()).pathname, 'the way out stays on /beta/').toContain('/beta/');

		const confirm = page.locator('a[href*="action=logout"][href*="_wpnonce"]');

		await navigated(page, () => confirm.click());

		expect(await whoOn(page, beta.url), 'signed out of /beta/').toBeNull();
		expect(await whoOn(page, hub.url), 'and of the hub').toBeNull();
		expect((await hub.site.user(email)).sessions, 'the session itself is gone').toBe(0);
	});

	test('somebody already signed in who opens the hub’s sign-in page with a way back goes straight back', async ({ page, hub, alpha, beta }) => {
		const email = freshEmail('hub-already-in');

		await beta.site.makeUser({ email, password: OLD });
		await signInFrom(page, beta, hub, email, OLD);

		await page.goto(`${hub.pages.login.url}?redirect_to=${encodeURIComponent(alpha.url)}`);
		await page.waitForURL((url) => url.href.startsWith(alpha.url), { waitUntil: 'domcontentloaded' });

		expect(page.url().startsWith(alpha.url), 'on /alpha/').toBe(true);
		expect((await page.context().cookies()).some((cookie) => cookie.name.startsWith('diluxone_users_return')), 'no way back left behind').toBe(false);
	});
});

test.describe('The shortcodes on /beta/ for somebody signed in', () => {
	test('the account is a door to the hub’s account; sign-in and registration draw nothing', async ({ page, hub, beta }) => {
		const email = freshEmail('hub-pieces-in');

		await beta.site.makeUser({ email, password: OLD });

		const one = await beta.site.page('hub-pieces', '[diluxone_users_account]\n\n[diluxone_users_login]\n\n[diluxone_users_register]');

		try {
			await signInFrom(page, beta, hub, email, OLD);
			await page.goto(one.url);

			await expect(hubDoor(page, 'account'), 'one door, to the account').toHaveCount(1);
			await expect(hubDoor(page, 'account').locator('a.diluxone-users-button')).toHaveAttribute('href', new RegExp(`^${hub.pages.account.url}`));
			await expect(hubDoor(page, 'login'), 'no sign-in for somebody signed in').toHaveCount(0);
			await expect(hubDoor(page, 'register'), 'no registration either').toHaveCount(0);
		} finally {
			await beta.site.forgetPage('hub-pieces');
		}
	});

	test('to a stranger, the registration door leads to the sign-in page when the hub’s registration form is closed', async ({ guest, hub, beta }) => {
		await hub.set({ diluxone_users_register_form: 0 });

		await guest.goto(beta.pages.register.url);

		const door = hubDoor(guest, 'register').locator('a.diluxone-users-button');

		await expect(door).toHaveAttribute('href', new RegExp(`^${hub.pages.login.url}`));
	});
});

test.describe('The admin bar and the menu', () => {
	test('/beta/’s admin bar “Edit profile” is the account on the hub', async ({ page, hub, beta }) => {
		await beta.set({ diluxone_users_bar_account: 1, diluxone_users_admin_bar: 'wp' });

		const email = freshEmail('hub-bar');

		await beta.site.makeUser({ email, password: OLD });
		await signInFrom(page, beta, hub, email, OLD);
		await page.goto(beta.url);

		// The account item at the head of the toolbar is WordPress's profile
		// address, which is what the setting changes.
		await expect(page.locator('#wp-admin-bar-my-account > a.ab-item')).toHaveAttribute('href', new RegExp(`^${hub.pages.account.url}`));
	});

	test('on the hub, the menu’s Sign in is the hub’s own page, with no way back to another site', async ({ guest, hub }) => {
		const menu = await hub.site.menu();

		await hub.set({ diluxone_users_menu_location: menu.location });

		try {
			await guest.goto(menu.url);

			const link = guest.locator('.diluxone-users-menu--sign-in a').first();

			await expect(link).toHaveAttribute('href', new RegExp(`^${hub.pages.login.url}`));

			const redirect = new URL((await link.getAttribute('href')) as string).searchParams.get('redirect_to') ?? '';

			expect(redirect.startsWith(`${NETWORK_URL}/alpha/`) || redirect.startsWith(`${NETWORK_URL}/beta/`), 'no way back to another site').toBe(false);
		} finally {
			await hub.site.forgetMenu();
		}
	});
});

test.describe('WordPress’s own sign-up of a network (wp-signup.php → wp-activate.php)', () => {
	test('an account made there and activated is a member of every site under “every site”, with no removal written', async ({ guest, hub, beta, network }) => {
		await network.set('registration', 'user');
		await hub.set({ diluxone_users_membership: 'all', diluxone_users_membership_confirmed: 1 });

		const email = freshEmail('hub-signup');
		const login = email.split('@')[0].replace(/[^a-z0-9]/g, '').slice(0, 50);

		await guest.goto(`${NETWORK_URL}/wp-signup.php`);
		await guest.locator('input[name="user_name"]').fill(login);
		await guest.locator('input[name="user_email"]').fill(email);

		const justUser = guest.locator('#signupuser');

		if ((await justUser.count()) > 0) {
			await justUser.check();
		}

		await navigated(guest, () => guest.locator('#setupform [type="submit"], #setupform .submit input').first().click());

		const link = linkIn(await waitForMail(hub.site, email, { subject: /activ/i }), /https?:\/\/\S*wp-activate\.php\S*/);

		await guest.goto(link);

		const made = await hub.site.user(email);

		expect(made.exists, 'the account is made').toBe(true);
		expect((await beta.site.user(email)).member, 'a member of /beta/ under “every site”').toBe(true);
		expect(userMeta(email, 'diluxone_users_removed_from'), 'no removal written by the activation').toBe('');
	});
});

test.describe('A site on a domain of its own, from the front', () => {
	test('its sign-in shortcode is a door to its own wp-login.php, and signing in there comes back with a session there', async ({ browser, hub }) => {
		await hub.set({ diluxone_users_membership: 'all', diluxone_users_membership_confirmed: 1 });

		const port = new URL(NETWORK_URL).port;
		const domain = `${MAPPED_HOST}${port ? `:${port}` : ''}`;
		const home = `http://${domain}/`;
		const site = throwawaySite('e2e-mapped-front');
		const email = freshEmail('hub-mapped');

		try {
			wp([
				'eval',
				`wp_update_site( ${site.id}, array( 'domain' => '${domain}', 'path' => '/' ) ); update_blog_option( ${site.id}, 'home', '${home.replace(/\/$/, '')}' ); update_blog_option( ${site.id}, 'siteurl', '${home.replace(/\/$/, '')}' );`,
			]);

			const page = wp(['post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Sign in', '--post_content=[diluxone_users_login]', '--porcelain'], home);
			const url = wp(['post', 'url', page], home);

			await hub.site.makeUser({ email, password: OLD });
			wp(['user', 'set-role', email, 'subscriber'], home);

			const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
			const tab = await context.newPage();

			try {
				await tab.goto(url);

				const door = tab.locator('.diluxone-users-hub-door[data-diluxone-users-door="here"] a.diluxone-users-button');

				await expect(door, 'a door to sign in here, not on the hub').toBeVisible();
				await expect(door).toHaveAttribute('href', new RegExp(`^${home}wp-login\\.php`));

				await navigated(tab, () => door.click());
				expect(new URL(tab.url()).host, 'its own wp-login.php').toBe(domain);

				await fillCredentials(tab, email, OLD);
				await Promise.all([tab.waitForURL((u) => u.href.startsWith(url), { waitUntil: 'domcontentloaded' }), tab.locator('#wp-submit').click()]);

				await expect(tab.locator('.diluxone-users-hub-door'), 'the door is gone for somebody signed in').toHaveCount(0);

				// The browser reaches the domain through its resolver rule; a
				// request context would not, so the session is asked of a page.
				await tab.goto(`${home}wp-admin/profile.php`);
				expect(new URL(tab.url()).pathname, 'a session on the site’s own domain').toBe('/wp-admin/profile.php');
			} finally {
				await context.close();
			}
		} finally {
			site.done();
		}
	});
});
