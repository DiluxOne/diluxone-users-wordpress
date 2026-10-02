import { test, expect, signInFrom } from './support';
import { freshEmail } from '../support/api';
import { navigated } from '../support/ui';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';

/**
 * The shared admin pieces where a network changes them: the field editor in
 * Network Admin, and the safe-mode notice, which is for whoever manages the
 * site it is shown on — a site's administrator yes, its editor no.
 */

test.use({ storageState: NETWORK_ADMIN_STATE });

const PASSWORD = 'e2e-Net-Framework-1!';

const network = (rest: string) => `${NETWORK_URL}/wp-admin/network/${rest.replace(/^\//, '')}`;

const TOWN = { key: 'e2e_net_town', label: 'E2E Net Town', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' };
const PLAIN = [
	{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
];

test.describe('The field editor in Network Admin', () => {
	test('opens over the list, Cancel saves nothing, and a change sent from it is what every site reads', async ({ page, hub, alpha }) => {
		await hub.set({ diluxone_users_fields: [...PLAIN, TOWN] });

		await page.goto(network('admin.php?page=diluxone-users-fields'));

		const edit = page.locator(`.row-actions .edit a[href*="field=${TOWN.key}"]`);
		const dialog = page.locator('dialog[data-diluxone-users-dialog]');
		const form = dialog.locator('form.diluxone-users-form-admin');

		await page.locator('tr').filter({ has: edit }).hover();
		await edit.click();
		await expect(dialog).toHaveJSProperty('open', true);
		await expect(form.locator('#diluxone-users-label')).toHaveValue(TOWN.label);
		await expect(page).not.toHaveURL(/field=/);

		await form.locator('#diluxone-users-label').fill('Cancelled');
		await dialog.locator('p.submit [data-diluxone-users-dialog-close]').click();
		await expect(dialog).toHaveJSProperty('open', false);

		const label = async (one: typeof hub) =>
			((await one.site.getOptions(['diluxone_users_fields'])).diluxone_users_fields as Array<{ key: string; label: string }>).find((f) => f.key === TOWN.key)!.label;

		expect(await label(hub)).toBe(TOWN.label);

		await page.locator('tr').filter({ has: edit }).hover();
		await edit.click();
		await expect(form).toBeVisible();
		await form.locator('#diluxone-users-label').fill('E2E Net City');
		await navigated(page, () => form.locator('p.submit [type="submit"]').first().click());

		expect(await label(hub)).toBe('E2E Net City');
		expect(await label(alpha), '/alpha/ reads the network’s fields').toBe('E2E Net City');
	});
});

test.describe('The safe-mode notice on a site of the network', () => {
	test('is shown to the site’s administrator and not to its editor', async ({ browser, hub, alpha }) => {
		const admin = freshEmail('net-safe-admin');
		const editor = freshEmail('net-safe-editor');

		await alpha.site.makeUser({ email: admin, password: PASSWORD, role: 'administrator' });
		await alpha.site.makeUser({ email: editor, password: PASSWORD, role: 'editor' });

		const asAdmin = await (await browser.newContext({ storageState: { cookies: [], origins: [] } })).newPage();
		const asEditor = await (await browser.newContext({ storageState: { cookies: [], origins: [] } })).newPage();

		try {
			await signInFrom(asAdmin, alpha, hub, admin, PASSWORD);
			await signInFrom(asEditor, alpha, hub, editor, PASSWORD);

			await alpha.set({ diluxone_e2e_safe_mode: 1 });

			await asAdmin.goto(alpha.admin(''));
			await expect(asAdmin.locator('[data-diluxone-users-safe-mode]')).toBeVisible();

			await asEditor.goto(alpha.admin(''));
			await expect(asEditor.locator('#wpadminbar')).toBeVisible();
			await expect(asEditor.locator('[data-diluxone-users-safe-mode]')).toHaveCount(0);

			// And /beta/, where it is not on, says nothing to anybody.
			await asAdmin.goto(`${NETWORK_URL}/beta/wp-admin/`);
			await expect(asAdmin.locator('[data-diluxone-users-safe-mode]')).toHaveCount(0);
		} finally {
			await asAdmin.context().close();
			await asEditor.context().close();
		}
	});
});
