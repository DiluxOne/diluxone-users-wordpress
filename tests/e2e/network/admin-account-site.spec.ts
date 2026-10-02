import { test, expect, whoOn, signInFrom } from './support';
import { freshEmail } from '../support/api';
import { savePanel } from '../support/ui';
import { NETWORK_ADMIN_STATE } from '../../../playwright.network.config';

/**
 * Account area on a network: every site keeps two tabs of its own — the site
 * menu and the WordPress dashboard — and what one site saves there is that
 * site's, met by its members on that site and nowhere else.
 */

test.use({ storageState: NETWORK_ADMIN_STATE });

const PASSWORD = 'e2e-Network-Content-1!';

const DASHBOARD = [
	'diluxone_users_wp_profile',
	'diluxone_users_wp_profile_scope',
	'diluxone_users_wp_profile_roles',
	'diluxone_users_admin_bar',
	'diluxone_users_admin_bar_scope',
	'diluxone_users_admin_bar_roles',
	'diluxone_users_admin_bar_keep_admins',
];

test.describe('Account area › a site of the network', () => {
	test('/alpha/’s Account screen draws only the menu and the dashboard; the hub’s tabs are not drawn there', async ({ page, alpha }) => {
		await page.goto(alpha.admin('admin.php?page=diluxone-users-account'));

		const tabs = await page.locator('.nav-tab-wrapper a.nav-tab').evaluateAll((all) => all.map((one) => new URL((one as HTMLAnchorElement).href).searchParams.get('tab')));

		expect(tabs.sort()).toEqual(['dashboard', 'menu']);

		for (const hubTab of ['sections', 'handle', 'page', 'summary']) {
			await page.goto(alpha.admin(`admin.php?page=diluxone-users-account&tab=${hubTab}`));
			await expect(page.locator('[data-diluxone-users-sortable], input[name="diluxone_users_handle_enabled"], select#diluxone_users_account_page'), `${hubTab} is not drawn on /alpha/`).toHaveCount(0);
		}
	});

	test('the profile sent to the account on /alpha/: its member lands on the hub’s details; /beta/ and the hub keep their own rule', async ({ page, guest, hub, alpha, beta }) => {
		for (const one of [hub, alpha, beta]) {
			await one.keep(DASHBOARD);
			await one.set({ diluxone_users_wp_profile: 'allow' });
		}

		const email = freshEmail('net-profile');

		await alpha.site.makeUser({ email, password: PASSWORD });
		await beta.site.makeUser({ email, password: PASSWORD });

		// Signed in first: the question "who is this" is asked of profile.php,
		// the screen this rule moves.
		await signInFrom(guest, alpha, hub, email, PASSWORD);
		expect(await whoOn(guest, alpha.url)).toBe(email);

		await page.goto(alpha.admin('admin.php?page=diluxone-users-account&tab=dashboard'));
		await page.locator('input[name="diluxone_users_wp_profile"][value="redirect"]').check({ force: true });
		await savePanel(page);

		expect((await alpha.site.getOptions(['diluxone_users_wp_profile'])).diluxone_users_wp_profile).toBe('redirect');
		expect((await beta.site.getOptions(['diluxone_users_wp_profile'])).diluxone_users_wp_profile, '/beta/ is untouched').toBe('allow');
		expect((await hub.site.getOptions(['diluxone_users_wp_profile'])).diluxone_users_wp_profile, 'the hub is untouched').toBe('allow');

		await guest.goto(alpha.admin('profile.php'));
		expect(guest.url().startsWith(hub.pages.account.url), 'sent to the account, which is the hub’s').toBe(true);
		expect(new URL(guest.url()).pathname).toMatch(/\/details\/$/);

		await guest.goto(beta.admin('profile.php'));
		expect(new URL(guest.url()).pathname).toBe(`/${beta.slug}/wp-admin/profile.php`);
		await expect(guest.locator('#your-profile')).toHaveCount(1);
	});

	test('the toolbar hidden on /alpha/: gone for its member there, still there on /beta/', async ({ page, guest, hub, alpha, beta }) => {
		for (const one of [alpha, beta]) {
			await one.keep(DASHBOARD);
			await one.set({ diluxone_users_admin_bar: 'wp', diluxone_users_admin_bar_keep_admins: 1 });
		}

		const email = freshEmail('net-bar');

		await alpha.site.makeUser({ email, password: PASSWORD });
		await beta.site.makeUser({ email, password: PASSWORD });

		await page.goto(alpha.admin('admin.php?page=diluxone-users-account&tab=dashboard'));
		await page.locator('input[name="diluxone_users_admin_bar"][value="hide-all"]').check({ force: true });
		await savePanel(page);

		expect((await alpha.site.getOptions(['diluxone_users_admin_bar'])).diluxone_users_admin_bar).toBe('hide');
		expect((await beta.site.getOptions(['diluxone_users_admin_bar'])).diluxone_users_admin_bar).toBe('wp');

		await signInFrom(guest, alpha, hub, email, PASSWORD);

		await guest.goto(alpha.url);
		await expect(guest.locator('#wpadminbar')).toHaveCount(0);

		await guest.goto(beta.url);
		await expect(guest.locator('#wpadminbar')).toHaveCount(1);
	});

	test('/beta/’s menu tab: the place and the look are saved for /beta/ alone and drawn in its menu', async ({ page, guest, hub, alpha, beta }) => {
		await beta.keep(['diluxone_users_menu_location', 'diluxone_users_menu_style']);
		await hub.keep(['diluxone_users_menu_location', 'diluxone_users_menu_style']);

		const hubBefore = await hub.site.getOptions(['diluxone_users_menu_location', 'diluxone_users_menu_style']);
		const menu = await beta.site.menu();

		try {
			await page.goto(beta.admin('admin.php?page=diluxone-users-account&tab=menu'));
			await page.locator('select[name="diluxone_users_menu_location"]').selectOption(menu.location);
			await page.locator('select[name="diluxone_users_menu_style"]').selectOption('name');
			await savePanel(page);

			expect(await beta.site.getOptions(['diluxone_users_menu_location', 'diluxone_users_menu_style'])).toEqual({
				diluxone_users_menu_location: menu.location,
				diluxone_users_menu_style: 'name',
			});
			expect(await hub.site.getOptions(['diluxone_users_menu_location', 'diluxone_users_menu_style']), 'the hub’s are its own').toEqual(hubBefore);

			await guest.goto(menu.url);

			const signIn = guest.locator('.diluxone-users-menu--sign-in a');

			await expect(signIn).toHaveAttribute('href', new RegExp(`^${hub.pages.login.url.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`));

			const email = freshEmail('net-menu');

			await alpha.site.makeUser({ email, password: PASSWORD });
			await beta.site.makeUser({ email, password: PASSWORD });
			await signInFrom(guest, beta, hub, email, PASSWORD);

			await guest.goto(menu.url);

			const person = guest.locator('.diluxone-users-menu--person > a');

			await expect(person).toBeVisible();
			await expect(person.locator('img'), '“name only”: no photo in the menu').toHaveCount(0);
		} finally {
			await beta.site.forgetMenu();
		}
	});
});
