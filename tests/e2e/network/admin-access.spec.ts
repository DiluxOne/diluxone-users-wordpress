import { test, expect, hubDoor, toTheHub, whoOn } from './support';
import { freshEmail } from '../support/api';
import { askForLink, registerForm, savePanel } from '../support/ui';
import { SCREENS } from '../support/screens';
import { wp } from '../support/cli';
import { PLAIN_FIELDS, card, control, expectPill } from '../support/admin-access';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';

/** A string matched as itself inside a regular expression: every character that means something to one is escaped. */
const literal = (text: string): string => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/**
 * Access on a network: the hub's screen, driven through its controls, and
 * what the other sites of the network get from it.
 *
 * On a subdirectory network Access lives on the main site — the hub — where
 * people sign in, register and keep their account. Another site does not
 * have it. The ways in that are the network's (social sign-in, passkeys) are
 * drawn there as they stand; WordPress's own registration form is the
 * network's too. What the hub saves is what /alpha/ and /beta/ send people
 * to.
 */

test.use({ storageState: NETWORK_ADMIN_STATE });

/** An address of the hub's dashboard. */
const onHub = (rest: string) => `${NETWORK_URL}/wp-admin/${rest.replace(/^\//, '')}`;
const accessOnHub = (tab: string) => onHub(`admin.php?page=diluxone-users-login&tab=${tab}`);

test.describe('The hub’s Access', () => {
	for (const tab of SCREENS['diluxone-users-login']) {
		test(`its “${tab}” tab answers and holds together`, async ({ page }) => {
			const problems: string[] = [];

			page.on('pageerror', (error) => problems.push(error.message));

			const response = await page.goto(accessOnHub(tab));

			expect(response?.status()).toBe(200);
			await expect(page.locator('.wrap.diluxone-users-admin')).toBeVisible();
			await expect(page.locator('.nav-tab-active')).toHaveAttribute('href', new RegExp(`tab=${tab}`));
			expect(await page.locator('body').innerText()).not.toMatch(/Fatal error|Warning:|Notice:|Deprecated:/);
			expect(problems, 'browser errors').toEqual([]);
		});
	}

	test('is not a screen of another site: asked for there, it is refused', async ({ page, alpha, beta }) => {
		for (const one of [alpha, beta]) {
			const response = await page.goto(one.admin('admin.php?page=diluxone-users-login&tab=ways'));

			expect(response?.status(), `/${one.slug}/`).toBe(403);
		}
	});

	test('Ways in draws the network’s doors as they stand, says where they are switched, and a forced press writes neither', async ({ page, hub }) => {
		await hub.set({ diluxone_users_sso_login: 1, diluxone_users_passkey_enabled: 0, diluxone_users_login_method: 'both' });

		await page.goto(accessOnHub('ways'));

		const sso = control(page, 'diluxone_users_sso_login');

		await expect(sso).toBeDisabled();
		await expect(control(page, 'diluxone_users_passkey_enabled')).toBeDisabled();
		await expect(page.locator('.diluxone-users-studio__fields .du-notice'), 'the note pointing to Network Admin').toHaveCount(1);

		await sso.evaluate((box: HTMLInputElement) => {
			box.disabled = false;
			box.checked = false;
		});
		await savePanel(page);

		expect(Number((await hub.site.getOptions(['diluxone_users_sso_login'])).diluxone_users_sso_login), 'a site screen switched the network’s social sign-in off').toBe(1);
	});

	test('Registration shows WordPress’s own form as the network has it, and never as a box to change', async ({ page, hub, network }) => {
		await hub.set({ diluxone_users_login_register: 1 });

		await network.set('registration', 'user');
		await page.goto(accessOnHub('register'));

		const box = control(page, 'diluxone_users_wp_register');

		await expect(box).toBeDisabled();
		await expect(box).toBeChecked();
		await expect(card(page, 'diluxone_users_wp_register')).toContainText('wp-signup.php');

		await network.set('registration', 'none');
		await page.reload();
		await expect(box).toBeDisabled();
		await expect(box).not.toBeChecked();

		// Saving the tab does not touch the network's answer.
		await savePanel(page);
		expect(wp(['site', 'option', 'get', 'registration'])).toBe('none');
	});

	test('“nobody can register”, saved on the hub, shuts every door the other sites lead to', async ({ page, browser, hub, alpha, beta, root, network }) => {
		await network.set('registration', 'user');
		await hub.set({
			diluxone_users_login_register: 1,
			diluxone_users_register_form: 1,
			diluxone_users_sso_register: 1,
			diluxone_users_fields: PLAIN_FIELDS,
			diluxone_users_login_method: 'both',
		});
		await hub.keep(['diluxone_users_register_page', 'diluxone_users_login_role', 'diluxone_users_rewrite_version']);

		await page.goto(accessOnHub('register'));
		await control(page, 'diluxone_users_register_open', 'closed').check();
		await savePanel(page);

		// The answer was kept, and the screen says where the other half is
		// decided instead of reading as if "nobody" had been ignored.
		const told = page.locator('[data-diluxone-users-network-registration]');
		await expect(told).toContainText('every door of this plugin is shut');
		await expect(told.locator('a')).toHaveAttribute('href', /\/wp-admin\/network\/settings\.php$/);

		const stored = await hub.site.getOptions(['diluxone_users_login_register', 'diluxone_users_register_form', 'diluxone_users_sso_register']);

		expect(Object.values(stored).map(Number), 'a door left open on the hub').toEqual([0, 0, 0]);

		// Every site reads the hub's doors.
		expect(Number((await beta.site.getOptions(['diluxone_users_login_register'])).diluxone_users_login_register)).toBe(0);

		// The network still takes accounts through WordPress's own form
		// (wp-signup.php), and the tab says so rather than pretending: that
		// door is the network's, shown ticked and locked.
		await expect(control(page, 'diluxone_users_wp_register')).toBeChecked();
		await expect(control(page, 'diluxone_users_wp_register')).toBeDisabled();

		// /beta/'s registration door now leads to the hub's sign-in page,
		// not to a form, and the hub's registration page draws none.
		const stranger = await (await browser.newContext({ storageState: { cookies: [], origins: [] } })).newPage();

		await stranger.goto(beta.pages.register.url);

		const door = (await hubDoor(stranger, 'register').locator('a.diluxone-users-button').getAttribute('href'))!;

		expect(door.startsWith(hub.pages.login.url), `the door leads to ${door}`).toBe(true);
		await stranger.goto(hub.pages.register.url);
		await expect(registerForm(stranger)).toHaveCount(0);

		// And the link from /alpha/ makes nobody an account.
		const email = freshEmail('net-closed');

		await toTheHub(stranger, alpha, hub);
		await askForLink(stranger, stranger.url(), email);
		expect((await root.user(email)).exists, 'the link door on the hub created an account').toBe(false);
		await stranger.context().close();
	});

	test('the sign-in page chosen on the hub is where the other sites’ doors lead, and wp-login.php follows the answer chosen there', async ({ page, browser, hub, beta }) => {
		await hub.set({ diluxone_users_login_method: 'both' });
		await hub.keep(['diluxone_users_login_page', 'diluxone_users_wp_screens']);

		const other = await hub.site.page('admin-access-net-login', '<!-- wp:shortcode -->[diluxone_users_login]<!-- /wp:shortcode -->');
		const stranger = await (await browser.newContext({ storageState: { cookies: [], origins: [] } })).newPage();

		try {
			await page.goto(accessOnHub('page'));
			await page.locator('#diluxone_users_login_page').selectOption(String(other.id));
			await control(page, 'diluxone_users_wp_screens', 'mine').check({ force: true });
			await savePanel(page);

			expect(Number((await hub.site.getOptions(['diluxone_users_login_page'])).diluxone_users_login_page)).toBe(other.id);

			// /beta/'s door leads to the new page.
			await stranger.goto(beta.pages.login.url);
			await expect(hubDoor(stranger, 'login').locator('a.diluxone-users-button')).toHaveAttribute('href', new RegExp(`^${literal(other.url)}`));

			// "Send everybody to the sign-in page": /beta/wp-login.php lands on it.
			await stranger.goto(`${beta.url}wp-login.php`);
			expect(new URL(stranger.url()).pathname).toBe(new URL(other.url).pathname);

			// "Keep it as a second screen": the hub's wp-login.php, with its form.
			await page.goto(accessOnHub('page'));
			await control(page, 'diluxone_users_wp_screens', 'wp').check({ force: true });
			await savePanel(page);
			expect((await hub.site.getOptions(['diluxone_users_wp_screens'])).diluxone_users_wp_screens).toBe('wp');

			await stranger.goto(`${beta.url}wp-login.php`);
			expect(stranger.url().startsWith(`${hub.url}wp-login.php`), 'the hub’s own wp-login.php').toBe(true);
			await expect(stranger.locator('#loginform')).toBeVisible();
		} finally {
			await stranger.context().close();
			await hub.site.forgetPage('admin-access-net-login');
		}
	});

	test('the summary on the hub reads the network’s doors as Network Admin set them', async ({ page, hub }) => {
		await hub.set({ diluxone_users_passkey_enabled: 1, diluxone_users_2fa_mode: 'optional' });

		await page.goto(accessOnHub('summary'));

		const rows = page.locator('table.diluxone-users-summary tbody tr');

		await expectPill(rows.nth(4).locator('.diluxone-users-summary__state'), 'active');
		await expectPill(rows.nth(6).locator('.diluxone-users-summary__state'), 'active');
		await expect(rows.nth(6).locator('a'), 'the second step is set in Network Admin').toHaveAttribute('href', /\/wp-admin\/network\/admin\.php\?page=diluxone-users-security/);

		await hub.set({ diluxone_users_passkey_enabled: 0, diluxone_users_2fa_mode: 'off' });
		await page.reload();
		await expectPill(rows.nth(4).locator('.diluxone-users-summary__state'), 'off');
		await expectPill(rows.nth(6).locator('.diluxone-users-summary__state'), 'off');
	});
});

test.describe('Create the page, on a network', () => {
	test('on the hub: made there, chosen there, and where /beta/’s registration door leads', async ({ page, browser, hub, beta }) => {
		await hub.set({ diluxone_users_register_page: 0, diluxone_users_register_form: 1, diluxone_users_login_register: 1 });
		await hub.keep(['diluxone_users_rewrite_version']);

		await page.goto(accessOnHub('register'));

		const create = page.locator('a.button[href*="action=diluxone_users_create_page"][href*="diluxone_users_register_page"]');

		await Promise.all([page.waitForURL(/diluxone_users_created=\d+/), create.click()]);

		const id = Number(new URL(page.url()).searchParams.get('diluxone_users_created'));

		try {
			expect(new URL(page.url()).pathname.startsWith('/wp-admin/'), 'back on the hub').toBe(true);
			expect(Number((await hub.site.getOptions(['diluxone_users_register_page'])).diluxone_users_register_page)).toBe(id);

			const made = (await page.locator('.notice-success a').getAttribute('href'))!;

			expect(made.startsWith(hub.url), 'the page is the hub’s').toBe(true);

			const stranger = await (await browser.newContext({ storageState: { cookies: [], origins: [] } })).newPage();

			await stranger.goto(beta.pages.register.url);
			await expect(hubDoor(stranger, 'register').locator('a.diluxone-users-button')).toHaveAttribute('href', new RegExp(`^${literal(made)}`));
			await stranger.context().close();
		} finally {
			await hub.site.forgetPageId(id);
		}
	});

	test('on another site: refused, nothing made there, and the hub’s page left alone', async ({ page, hub, alpha }) => {
		await hub.set({ diluxone_users_register_page: 0, diluxone_users_register_form: 1, diluxone_users_login_register: 1 });

		await page.goto(accessOnHub('register'));

		// The super admin's own nonce, which is good on every site of the
		// network: what refuses is that the pages are the hub's.
		const href = (await page.locator('a.button[href*="action=diluxone_users_create_page"]').first().getAttribute('href'))!;
		const query = new URL(href, page.url()).search;

		const before = await page.request.get(alpha.admin('edit.php?post_type=page&post_status=all')).then((r) => r.text());
		const answer = await page.goto(alpha.admin(`admin-post.php${query}`));

		expect(answer?.status()).toBe(403);
		expect(Number((await hub.site.getOptions(['diluxone_users_register_page'])).diluxone_users_register_page)).toBe(0);

		const after = await page.request.get(alpha.admin('edit.php?post_type=page&post_status=all')).then((r) => r.text());

		expect((after.match(/<tr id="post-\d+"/g) ?? []).length, 'a page was made on /alpha/').toBe((before.match(/<tr id="post-\d+"/g) ?? []).length);
	});
});

test.describe('The live preview, on the hub', () => {
	test('redraws from the form and writes nothing, on the hub or for the network', async ({ page, hub }) => {
		await hub.set({ diluxone_users_sso_button_text: '', diluxone_e2e_sso: 1, diluxone_users_sso: { mock: { active: 1, id: 'a', secret: 'b', tested: 1 } } });

		const keys = ['diluxone_users_sso_button_text', 'diluxone_users_sso', 'diluxone_users_sso_login', 'diluxone_users_passkey_enabled'];
		const before = await hub.site.getOptions(keys);

		await page.goto(onHub('admin.php?page=diluxone-users-design&tab=social'));

		const [answer] = await Promise.all([
			page.waitForResponse((response) => response.url().includes('admin-ajax.php') && (response.request().postData() ?? '').includes('action=diluxone_users_preview')),
			page.locator('input[name="diluxone_users_sso_button_text"]').fill('Netwide %s'),
		]);

		expect((await answer.json()).success).toBe(true);
		expect(JSON.stringify((await answer.json()).data)).toContain('Netwide');
		expect(await hub.site.getOptions(keys), 'the preview wrote a setting').toEqual(before);
		expect(await whoOn(page, hub.url), 'still the same session').not.toBeNull();
	});
});
