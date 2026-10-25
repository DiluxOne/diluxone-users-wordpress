import { test, expect, toTheHub } from './support';
import { savePanel, signInWithPassword } from '../support/ui';
import { freshEmail } from '../support/api';
import { resolvedColour, rgb } from '../support/admin-content';
import { NETWORK_ADMIN_STATE } from '../../../playwright.network.config';

/**
 * The hub's Design on a network: saved on the main site's screens, and met by
 * whoever comes from another site — the hub's sign-in page in its words and
 * colour, and the other site's door in the same colour.
 */

test.use({ storageState: NETWORK_ADMIN_STATE });

const PASSWORD = 'e2e-DesignHub-1!';

test('the hub’s sign-in words and accent are what somebody sent from /alpha/ meets, and /alpha/’s door wears the same accent', async ({ page, guest, hub, alpha }) => {
	await hub.keep(['diluxone_users_login_title', 'diluxone_users_style_accent', 'diluxone_users_styles', 'diluxone_users_colors']);
	await hub.set({ diluxone_users_styles: 1, diluxone_users_colors: 'own' });

	const heading = `E2E hub heading ${Date.now().toString(36)}`;

	await page.goto(`${hub.url}wp-admin/admin.php?page=diluxone-users-design&tab=login`);
	await page.locator('[name="diluxone_users_login_title"]').fill(heading);
	await savePanel(page);

	await page.goto(`${hub.url}wp-admin/admin.php?page=diluxone-users-design&tab=brand`);
	await page.locator('input[name="diluxone_users_look"][value="own"]').check({ force: true });
	await page.locator('input[name="diluxone_users_style_accent"][value="#6d28d9"]').check({ force: true });
	await savePanel(page);

	await guest.goto(alpha.pages.login.url);
	expect(await resolvedColour(guest, '--diluxone-users-accent'), '/alpha/’s door is painted with the hub’s accent').toBe(rgb('#6d28d9'));

	await toTheHub(guest, alpha, hub);
	await expect(guest.locator('.diluxone-users-login__title')).toHaveText(heading);
	expect(await resolvedColour(guest, '--diluxone-users-accent')).toBe(rgb('#6d28d9'));
});

test('with no logo set on the hub, its switch is off and its link opens the hub’s Your brand', async ({ page, hub }) => {
	await hub.set({ diluxone_users_login_logo: 0 });

	await page.goto(`${hub.url}wp-admin/admin.php?page=diluxone-users-design&tab=login`);

	const box = page.locator('input[name="diluxone_users_login_logo_show"]');

	await expect(box).toBeDisabled();
	await expect(box).not.toBeChecked();

	await page.getByRole('link', { name: 'It is set on Your brand →' }).click();
	await expect(page).toHaveURL(/page=diluxone-users-design&tab=brand/);
	expect(page.url().startsWith(hub.url)).toBe(true);
});

test('the hub’s “above and below” is the room above its account area’s header and under the taller of the side menu and the content', async ({ page, hub }) => {
	const ROOM = 36;

	await hub.keep(['diluxone_users_account_template', 'diluxone_users_account_layout', 'diluxone_users_account_header', 'diluxone_users_account_body_pad', 'diluxone_users_2fa_mode']);
	await hub.set({
		diluxone_users_account_template: 'plain',
		diluxone_users_account_layout: 'side',
		diluxone_users_account_header: 1,
		diluxone_users_account_body_pad: String(ROOM),
		diluxone_users_2fa_mode: 'off',
	});

	const email = freshEmail('net-body-pad');

	await hub.site.makeUser({ email, password: PASSWORD });
	await page.context().clearCookies();
	await page.goto(hub.pages.login.url);
	await signInWithPassword(page, email, PASSWORD);
	await page.goto(hub.pages.account.url);
	await expect(page.locator('.diluxone-users-account__header')).toBeVisible();

	const gaps = await page.evaluate(() => {
		const whole = document.querySelector('.diluxone-users-account')!.getBoundingClientRect();
		const header = document.querySelector('.diluxone-users-account__header')!.getBoundingClientRect();
		const ends = [...document.querySelectorAll('.diluxone-users-account__nav .diluxone-users-account__tab, .diluxone-users-account__section')].map((element) => element.getBoundingClientRect().bottom);

		return { above: header.top - whole.top, below: whole.bottom - Math.max(...ends) };
	});

	expect(Math.abs(gaps.above - ROOM), 'the room above the header').toBeLessThanOrEqual(1);
	expect(gaps.below, 'the room under the taller of menu and content').toBeGreaterThanOrEqual(ROOM - 1);
});
