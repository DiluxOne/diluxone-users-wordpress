import { test, expect, signInFrom } from './support';
import { freshEmail } from '../support/api';
import { navigated } from '../support/ui';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';

/**
 * User fields in Network Admin: the order the arrows give is the network's,
 * the list comes back to Network Admin, and the account a member opens from
 * any site asks in that order.
 */

test.use({ storageState: NETWORK_ADMIN_STATE });

const PASSWORD = 'e2e-Network-Fields-1!';
const FIELDS = `${NETWORK_URL}/wp-admin/network/admin.php?page=diluxone-users-fields`;

const field = (key: string, label: string) => ({ key, label, type: 'text', required: 0, active: 1, group: 'main', edit: 'always' });

test('the arrows in Network Admin reorder the network’s fields; the list stays in Network Admin and every site reads the order', async ({
	page,
	guest,
	hub,
	alpha,
	beta,
}) => {
	await hub.keep(['diluxone_users_fields']);
	await hub.set({
		diluxone_users_fields: [
			field('first_name', 'First name'),
			field('last_name', 'Last name'),
			field('diluxone_users_e2e_a', 'E2E A'),
			field('diluxone_users_e2e_b', 'E2E B'),
			field('diluxone_users_e2e_c', 'E2E C'),
		],
	});

	const order = async (site: typeof beta) =>
		((await site.site.getOptions(['diluxone_users_fields'])).diluxone_users_fields as Array<{ key: string }>).map((one) => one.key).filter((key) => key.startsWith('diluxone_users_e2e_'));

	const press = async (key: string, way: 'up' | 'down') => {
		await page.goto(FIELDS);
		await navigated(page, () => page.locator(`a[href*="diluxone_users_action=${way}"][href*="field=${key}"]`).click());

		const landed = new URL(page.url());

		expect(landed.pathname, 'back on the list in Network Admin').toBe('/wp-admin/network/admin.php');
		expect(landed.searchParams.get('diluxone_users_done')).toBe(way);
	};

	await press('diluxone_users_e2e_a', 'down');
	expect(await order(beta)).toEqual(['diluxone_users_e2e_b', 'diluxone_users_e2e_a', 'diluxone_users_e2e_c']);

	await press('diluxone_users_e2e_c', 'up');
	expect(await order(beta), '/beta/ reads the network’s order').toEqual(['diluxone_users_e2e_b', 'diluxone_users_e2e_c', 'diluxone_users_e2e_a']);
	expect(await order(alpha)).toEqual(['diluxone_users_e2e_b', 'diluxone_users_e2e_c', 'diluxone_users_e2e_a']);

	const email = freshEmail('net-order');

	await alpha.site.makeUser({ email, password: PASSWORD });
	await signInFrom(guest, alpha, hub, email, PASSWORD);
	await guest.goto(`${hub.pages.account.url.replace(/\/?$/, '/')}details/`);

	const asked = await guest
		.locator('form.diluxone-users-form input[type="text"]')
		.evaluateAll((all) => all.map((one) => (one as HTMLInputElement).name).filter((name) => name.startsWith('diluxone_users_e2e_')));

	expect(asked, 'the account asks in the same order').toEqual(['diluxone_users_e2e_b', 'diluxone_users_e2e_c', 'diluxone_users_e2e_a']);
});

test('a field renamed in Network Admin keeps its key and the answer a member gave on the hub', async ({ page, guest, hub, alpha }) => {
	await hub.keep(['diluxone_users_fields']);
	await hub.set({ diluxone_users_fields: [field('first_name', 'First name'), field('last_name', 'Last name'), field('diluxone_users_e2e_city', 'E2E City')] });

	const email = freshEmail('net-rename');

	await alpha.site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_e2e_city: 'Rosario' } });

	await page.goto(`${FIELDS}&field=diluxone_users_e2e_city`);
	await page.locator('[name="diluxone_users_field[label]"]').fill('E2E Town');
	await navigated(page, () => page.locator('form.diluxone-users-form-admin #submit').click());
	expect(new URL(page.url()).searchParams.get('diluxone_users_done')).toBe('saved');

	const stored = (await alpha.site.getOptions(['diluxone_users_fields'])).diluxone_users_fields as Array<{ key: string; label: string }>;

	expect(stored.filter((one) => one.key === 'diluxone_users_e2e_city')).toEqual([expect.objectContaining({ label: 'E2E Town' })]);

	await signInFrom(guest, alpha, hub, email, PASSWORD);
	await guest.goto(`${hub.pages.account.url.replace(/\/?$/, '/')}details/`);
	await expect(guest.locator('input[name="diluxone_users_e2e_city"]')).toHaveValue('Rosario');
	await expect(guest.locator('label[for="diluxone_users_e2e_city"]')).toContainText('E2E Town');
});
