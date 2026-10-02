import { Page } from '@playwright/test';
import { test, expect, stateOf } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { accountSection, linkForm, emailField, openWay } from '../support/ui';
import { PASSWORD, PIXEL, formOf, nonceOf, png, postByHand, reveal, send, signedIn } from '../support/my-account';

/**
 * "Your details": the fields of every type, the edit rules, the photo and
 * the public name — each driven through its own control, then read back from
 * the account through the side door and from the page after a reload.
 *
 * Where the browser would stop a value before the server sees it (a closed
 * list, `readonly`, `maxlength`), the test takes the browser's check away and
 * asks the server, which is the rule.
 */

const BASE = [
	{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'e2e_city', label: 'City', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
];

/** One field of every type the plugin draws, beyond plain text. */
const TYPES = [
	{ key: 'e2e_bio', label: 'Bio', type: 'textarea' },
	{ key: 'e2e_mail', label: 'Work mail', type: 'email' },
	{ key: 'e2e_phone', label: 'Phone', type: 'phone', options: ['AR'] },
	{ key: 'e2e_country', label: 'Country', type: 'country', options: ['AR', 'CL'] },
	{ key: 'e2e_site', label: 'Site', type: 'url' },
	{ key: 'e2e_num', label: 'Number', type: 'number' },
	{ key: 'e2e_day', label: 'Day', type: 'date' },
	{ key: 'e2e_pick', label: 'Pick', type: 'select', options: ['a', 'b'] },
	{ key: 'e2e_fav', label: 'Favourite', type: 'datalist', options: ['x', 'y'] },
	{ key: 'e2e_ok', label: 'Agree', type: 'checkbox' },
].map((field) => ({ required: 0, active: 1, group: 'extra', edit: 'always', options: [], ...field }));

const fieldsForm = (page: Page) => formOf(page, 'diluxone_users_fields_save');

/** Saves the details form from the account and answers with the state. */
async function saveDetails(page: Page): Promise<string> {
	return send(page, fieldsForm(page).locator('button[type="submit"]').first());
}

test.beforeEach(async ({ options }) => {
	await options.set({ diluxone_users_fields: BASE, diluxone_users_2fa_mode: 'off' });
});

test.describe('Fields', () => {
	test('every type is drawn, cleaned on the way in and drawn back as stored', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_fields: [...BASE, ...TYPES] });

		const { email } = await signedIn(page, site, pages.login.url, 'types');

		await page.goto(accountSection(pages.account.url, 'details'));
		await reveal(page, 'textarea[name="e2e_bio"]');

		const form = fieldsForm(page);

		await form.locator('textarea[name="e2e_bio"]').fill('Line one\nLine <b>two</b>');
		await form.locator('input[name="e2e_mail"]').fill('ada@example.org');
		await form.locator('select[name="e2e_phone_dial"]').selectOption('AR');
		await form.locator('input[name="e2e_phone"]').fill('11 2345-6789');
		await form.locator('select[name="e2e_country"]').selectOption('CL');
		await form.locator('input[name="e2e_site"]').fill('https://example.org/me');
		await form.locator('input[name="e2e_num"]').fill('42');
		await form.locator('input[name="e2e_day"]').fill('2026-01-02');
		await form.locator('select[name="e2e_pick"]').selectOption('b');
		await form.locator('input[name="e2e_fav"]').fill('something free');
		await form.locator('input[type="checkbox"][name="e2e_ok"]').check();

		expect(await saveDetails(page)).toBe('saved');

		const keys = TYPES.map((field) => field.key);
		const stored = (await site.user(email, keys)).fields;

		// A browser sends a textarea's lines as CRLF; the line break is what counts.
		stored.e2e_bio = String(stored.e2e_bio).replace(/\r\n/g, '\n');

		expect(stored).toEqual({
			e2e_bio: 'Line one\nLine two',
			e2e_mail: 'ada@example.org',
			e2e_phone: '+541123456789',
			e2e_country: 'CL',
			e2e_site: 'https://example.org/me',
			e2e_num: '42',
			e2e_day: '2026-01-02',
			e2e_pick: 'b',
			e2e_fav: 'something free',
			e2e_ok: '1',
		});

		// Drawn back as they were stored, the phone split again into its two parts.
		await page.reload();
		await reveal(page, 'textarea[name="e2e_bio"]');
		await expect(form.locator('select[name="e2e_phone_dial"]')).toHaveValue('AR');
		await expect(form.locator('input[name="e2e_phone"]')).toHaveValue('1123456789');
		await expect(form.locator('select[name="e2e_country"]')).toHaveValue('CL');
		await expect(form.locator('select[name="e2e_pick"]')).toHaveValue('b');
		await expect(form.locator('input[type="checkbox"][name="e2e_ok"]')).toBeChecked();
	});

	test('a value off a closed list, a broken address or a day that does not exist is not stored', async ({
		page,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_fields: [...BASE, ...TYPES] });

		const { email } = await signedIn(page, site, pages.login.url, 'types-forged');

		await page.goto(accountSection(pages.account.url, 'details'));
		await reveal(page, 'select[name="e2e_pick"]');

		const form = fieldsForm(page);

		// What the browser would never send, sent: the list gains an option,
		// the boxes lose their types, and the form its check.
		await form.evaluate((element: HTMLFormElement) => {
			element.noValidate = true;

			const pick = element.querySelector('select[name="e2e_pick"]') as HTMLSelectElement;
			pick.add(new Option('zzz', 'zzz'));

			const country = element.querySelector('select[name="e2e_country"]') as HTMLSelectElement;
			country.add(new Option('Atlantis', 'XX'));

			for (const name of ['e2e_mail', 'e2e_day', 'e2e_num']) {
				(element.querySelector(`input[name="${name}"]`) as HTMLInputElement).type = 'text';
			}
		});
		await form.locator('select[name="e2e_pick"]').selectOption('zzz');
		await form.locator('select[name="e2e_country"]').selectOption('XX');
		await form.locator('input[name="e2e_mail"]').fill('nope');
		await form.locator('input[name="e2e_day"]').fill('2026-02-30');
		await form.locator('input[name="e2e_num"]').fill('1e999');

		expect(await saveDetails(page)).toBe('saved');

		const stored = (await site.user(email, ['e2e_pick', 'e2e_country', 'e2e_mail', 'e2e_day', 'e2e_num'])).fields;

		expect(stored).toEqual({ e2e_pick: '', e2e_country: '', e2e_mail: '', e2e_day: '', e2e_num: '' });
	});

	test('a tick box ticked can be unticked from the account', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_fields: [...BASE, TYPES.find((field) => field.type === 'checkbox')] });

		const { email } = await signedIn(page, site, pages.login.url, 'tick', { meta: { e2e_ok: '1' } });

		await page.goto(accountSection(pages.account.url, 'details'));
		await reveal(page, 'input[type="checkbox"][name="e2e_ok"]');
		await expect(fieldsForm(page).locator('input[type="checkbox"][name="e2e_ok"]')).toBeChecked();

		await fieldsForm(page).locator('input[type="checkbox"][name="e2e_ok"]').uncheck();
		expect(await saveDetails(page)).toBe('saved');

		expect(
			(await site.user(email, ['e2e_ok'])).fields.e2e_ok,
			'bug: an unticked box sends nothing and the save skips a key that did not arrive, so a tick box can never be unticked from the account (includes/fields.php diluxone_users_save, array_key_exists)'
		).toBe('');
	});

	test('a field nobody may change is shown read-only, and a value sent anyway is not saved', async ({ page, site, pages, options }) => {
		await options.set({
			diluxone_users_fields: [...BASE, { key: 'e2e_id', label: 'Member id', type: 'text', required: 0, active: 1, group: 'main', edit: 'never' }],
		});

		const { email } = await signedIn(page, site, pages.login.url, 'never', { meta: { e2e_id: 'X1' } });

		await page.goto(accountSection(pages.account.url, 'details'));
		await reveal(page, 'input[name="e2e_id"]');

		const box = fieldsForm(page).locator('input[name="e2e_id"]');

		await expect(box).toHaveAttribute('readonly', '');
		await expect(fieldsForm(page).locator('.diluxone-users-field:has(input[name="e2e_id"]) .diluxone-users-field__limit')).toBeVisible();

		await box.evaluate((input: HTMLInputElement) => input.removeAttribute('readonly'));
		await box.fill('HACK');
		expect(await saveDetails(page)).toBe('saved');

		expect((await site.user(email, ['e2e_id'])).fields.e2e_id).toBe('X1');
	});

	test('a field that may change once: the first change is kept and counted, then it locks and the server refuses the next', async ({
		page,
		site,
		pages,
		options,
	}) => {
		await options.set({
			diluxone_users_fields: [
				...BASE,
				{ key: 'e2e_nick', label: 'Nick', type: 'text', required: 0, active: 1, group: 'main', edit: 'limited', edit_max: 1 },
			],
		});

		const { email } = await signedIn(page, site, pages.login.url, 'limited');

		await page.goto(accountSection(pages.account.url, 'details'));
		await reveal(page, 'input[name="e2e_nick"]');

		const box = fieldsForm(page).locator('input[name="e2e_nick"]');
		const note = fieldsForm(page).locator('.diluxone-users-field:has(input[name="e2e_nick"]) .diluxone-users-field__limit');

		await expect(box).not.toHaveAttribute('readonly', /.*/);
		await expect(note).toContainText('1');

		await box.fill('first');
		expect(await saveDetails(page)).toBe('saved');

		let stored = (await site.user(email, ['e2e_nick', 'diluxone_users_edits_e2e_nick'])).fields;

		expect(stored).toEqual({ e2e_nick: 'first', diluxone_users_edits_e2e_nick: '1' });

		await reveal(page, 'input[name="e2e_nick"]');
		await expect(box, 'no changes left: read-only').toHaveAttribute('readonly', '');
		await expect(note).not.toContainText('1');

		await box.evaluate((input: HTMLInputElement) => input.removeAttribute('readonly'));
		await box.fill('second');
		expect(await saveDetails(page)).toBe('saved');

		stored = (await site.user(email, ['e2e_nick', 'diluxone_users_edits_e2e_nick'])).fields;
		expect(stored).toEqual({ e2e_nick: 'first', diluxone_users_edits_e2e_nick: '1' });
	});

	test('a field named after a key the site keeps for itself never writes it', async ({ page, site, pages, options }) => {
		await options.set({
			diluxone_users_fields: [
				...BASE,
				{ key: 'wp_capabilities', label: 'Caps', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
				{ key: 'diluxone_users_closed', label: 'Closed', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
			],
		});

		const { email } = await signedIn(page, site, pages.login.url, 'reserved-key');

		await page.goto(accountSection(pages.account.url, 'details'));
		await reveal(page, 'input[name="first_name"]');

		const form = fieldsForm(page);

		// Drawn or not, the save is asked: by hand if the form leaves them out.
		const caps = form.locator('input[name="wp_capabilities"]');

		if ((await caps.count()) > 0) {
			await caps.fill('a:1:{s:13:"administrator";b:1;}');
			await form.locator('input[name="diluxone_users_closed"]').fill('1');
			expect(await saveDetails(page)).toBe('saved');
		} else {
			const nonce = await nonceOf(page, 'diluxone_users_fields_save');

			await postByHand(page, {
				action: 'diluxone_users_fields_save',
				_wpnonce: nonce,
				diluxone_users_group: '',
				wp_capabilities: 'a:1:{s:13:"administrator";b:1;}',
				diluxone_users_closed: '1',
			});
		}

		const after = await site.user(email, ['diluxone_users_closed']);

		expect(after.roles).toEqual(['subscriber']);
		expect(after.fields.diluxone_users_closed).toBe('');
	});

	test('[diluxone_users_fields group="extra"] draws that block only, and saving it leaves the other block’s required fields alone', async ({
		page,
		site,
		pages,
		options,
	}) => {
		await options.set({
			diluxone_users_fields: [
				{ key: 'e2e_city', label: 'City', type: 'text', required: 1, active: 1, group: 'main', edit: 'always' },
				{ key: 'e2e_hobby', label: 'Hobby', type: 'text', required: 0, active: 1, group: 'extra', edit: 'always' },
			],
		});

		const slug = 'my-account-extra';
		const piece = await site.page(slug, '[diluxone_users_fields group="extra"]');

		try {
			const { email } = await signedIn(page, site, pages.login.url, 'group');

			await page.goto(piece.url);

			const form = page.locator('.diluxone-users-fields form');

			await expect(form.locator('input[name="e2e_hobby"]')).toBeVisible();
			await expect(form.locator('input[name="e2e_city"]'), 'the main block is not on this page').toHaveCount(0);

			await form.locator('input[name="e2e_hobby"]').fill('Chess');
			expect(await send(page, form.locator('button[type="submit"]'))).toBe('saved');

			expect((await site.user(email, ['e2e_hobby', 'e2e_city'])).fields).toEqual({ e2e_hobby: 'Chess', e2e_city: '' });
		} finally {
			await site.forgetPage(slug);
		}
	});

	test('with no active field the fields shortcode draws nothing', async ({ page, site, pages, options }) => {
		// Fields there are, none of them active: nothing for the form to draw.
		// WordPress's own two are listed too, switched off, or the dashboard
		// puts them back at the head of the list as missing natives.
		await options.set({
			diluxone_users_fields: ['first_name', 'last_name', 'e2e_city'].map((key) => ({
				key,
				label: key,
				type: 'text',
				required: 0,
				active: 0,
				group: 'main',
				edit: 'always',
			})),
		});

		const slug = 'my-account-nofields';
		const piece = await site.page(slug, '<p id="e2e-marker">x</p>[diluxone_users_fields]');

		try {
			await signedIn(page, site, pages.login.url, 'nofields');
			await page.goto(piece.url);
			await expect(page.locator('#e2e-marker')).toBeVisible();
			await expect(page.locator('.diluxone-users-fields')).toHaveCount(0);
		} finally {
			await site.forgetPage(slug);
		}
	});
});

test.describe('Photo', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({ diluxone_users_avatar_upload: 1, diluxone_users_avatar_max_kb: 2048 });
	});

	const avatarForm = (page: Page) => formOf(page, 'diluxone_users_avatar');

	async function upload(page: Page, accountUrl: string, file: { name: string; mimeType: string; buffer: Buffer } | null): Promise<void> {
		await page.goto(accountSection(accountUrl, 'details'));
		await reveal(page, 'form.diluxone-users-avatar__form');

		if (file) {
			await avatarForm(page).locator('input[name="diluxone_users_avatar_file"]').setInputFiles(file);
		}

		await Promise.all([page.waitForURL(/\/details\//), avatarForm(page).locator('button[type="submit"]').first().click()]);
		await page.waitForLoadState('domcontentloaded');
	}

	const error = (page: Page) => page.locator('.diluxone-users-avatar .diluxone-users-notice--error');

	test('a picture heavier than the site allows is refused, and the note says the limit', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_avatar_max_kb: 1 });

		const { email } = await signedIn(page, site, pages.login.url, 'photo-heavy');

		await upload(page, pages.account.url, { name: 'heavy.png', mimeType: 'image/png', buffer: png(10, 10, 5000) });

		await expect(error(page)).toContainText('1 KB');
		expect(stateOf(page.url())).not.toBe('saved');
		await reveal(page, 'form.diluxone-users-avatar__form');
		await expect(avatarForm(page).locator('.diluxone-users-note')).toContainText('1 KB');
		expect((await site.user(email, ['diluxone_users_avatar'])).fields.diluxone_users_avatar).toBe('');
	});

	test('a picture of more than 6000 pixels a side is refused before anything opens it', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'photo-huge');

		await upload(page, pages.account.url, { name: 'wide.png', mimeType: 'image/png', buffer: png(6001, 1) });

		await expect(error(page)).toContainText('6000');
		expect((await site.user(email, ['diluxone_users_avatar'])).fields.diluxone_users_avatar).toBe('');
	});

	test('Save with no file chosen says nothing arrived and changes nothing', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'photo-none');

		await upload(page, pages.account.url, null);

		await expect(error(page)).toBeVisible();
		expect(stateOf(page.url())).not.toBe('saved');
		expect((await site.user(email, ['diluxone_users_avatar'])).fields.diluxone_users_avatar).toBe('');
	});

	test('a new picture replaces the old one, and the old attachment goes', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'photo-replace');

		await upload(page, pages.account.url, { name: 'one.png', mimeType: 'image/png', buffer: PIXEL });

		const first = Number((await site.user(email, ['diluxone_users_avatar'])).fields.diluxone_users_avatar);

		expect(first).toBeGreaterThan(0);

		await upload(page, pages.account.url, { name: 'two.png', mimeType: 'image/png', buffer: png(2, 2) });

		const second = Number((await site.user(email, ['diluxone_users_avatar'])).fields.diluxone_users_avatar);

		expect(second).toBeGreaterThan(0);
		expect(second).not.toBe(first);
		expect((await page.request.get(`/?attachment_id=${first}`)).status(), 'the first one is gone').toBe(404);
		expect((await page.request.get(`/?attachment_id=${second}`)).status()).toBe(200);
	});

	test('with uploads switched off, a picture posted by hand is refused by the server', async ({ page, site, pages, options }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'photo-off');

		await page.goto(accountSection(pages.account.url, 'details'));

		const nonce = await nonceOf(page, 'diluxone_users_avatar');

		await options.set({ diluxone_users_avatar_upload: 0 });
		await page.reload();
		await expect(avatarForm(page)).toHaveCount(0);

		const answer = await postByHand(
			page,
			{ action: 'diluxone_users_avatar', _wpnonce: nonce },
			{ multipart: { diluxone_users_avatar_file: { name: 'me.png', mimeType: 'image/png', buffer: PIXEL } } }
		);

		expect(answer.state).not.toBe('saved');
		expect((await site.user(email, ['diluxone_users_avatar'])).fields.diluxone_users_avatar).toBe('');
	});

	test('[diluxone_users_avatar] on a page of its own uploads and lands on the account details, saved', async ({ page, site, pages }) => {
		const slug = 'my-account-avatar';
		const piece = await site.page(slug, '[diluxone_users_avatar]');

		try {
			const { email } = await signedIn(page, site, pages.login.url, 'photo-piece');

			await page.goto(piece.url);
			await avatarForm(page).locator('input[name="diluxone_users_avatar_file"]').setInputFiles({ name: 'me.png', mimeType: 'image/png', buffer: PIXEL });
			expect(await send(page, avatarForm(page).locator('button[type="submit"]').first())).toBe('saved');

			expect(page.url()).toContain(accountSection(pages.account.url, 'details'));
			expect(Number((await site.user(email, ['diluxone_users_avatar'])).fields.diluxone_users_avatar)).toBeGreaterThan(0);
		} finally {
			await site.forgetPage(slug);
		}
	});
});

test.describe('Public name', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_users_handle_enabled: 1,
			diluxone_users_handle_cooldown: 0,
			diluxone_users_handle_min: 3,
			diluxone_users_handle_max: 50,
			diluxone_users_handle_spaces: 'dash',
			diluxone_users_handle_reserved: '',
			diluxone_users_handle_login: 0,
		});
	});

	const handleForm = (page: Page) => formOf(page, 'diluxone_users_handle');
	const handleBox = (page: Page) => page.locator('#diluxone-users-handle');
	const handleError = (page: Page) => page.locator('.diluxone-users-handle .diluxone-users-notice--error');

	/** Types a name in the account's box and presses Save; answers with the state (empty on a refusal). */
	async function tryName(page: Page, accountUrl: string, name: string): Promise<string> {
		await page.goto(accountSection(accountUrl, 'details'));
		await reveal(page, '#diluxone-users-handle');
		await handleForm(page).evaluate((form: HTMLFormElement) => {
			form.noValidate = true;
		});
		await handleBox(page).evaluate((input: HTMLInputElement) => {
			input.removeAttribute('maxlength');
			input.removeAttribute('minlength');
		});
		await handleBox(page).fill(name);
		await Promise.all([page.waitForURL(/\/details\//), handleForm(page).locator('button[type="submit"]').click()]);
		await page.waitForLoadState('domcontentloaded');

		return stateOf(page.url());
	}

	async function handleOf(site: any, email: string): Promise<string> {
		return (await site.user(email, ['diluxone_users_handle'])).fields.diluxone_users_handle;
	}

	test('a name on the site’s own reserved list is refused', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_handle_reserved: 'bigboss, staff' });

		const { email } = await signedIn(page, site, pages.login.url, 'h-list');

		expect(await tryName(page, pages.account.url, 'staff')).not.toBe('saved');
		await expect(handleError(page)).toBeVisible();
		expect(await handleOf(site, email)).toBe('');
	});

	test('a name somebody else already answers to is refused, and theirs is untouched', async ({ page, site, pages }) => {
		const owner = freshEmail('h-owner');

		await site.makeUser({ email: owner, password: PASSWORD });

		const taken = String((await site.user(owner)).nicename);
		const { email } = await signedIn(page, site, pages.login.url, 'h-taker');

		expect(await tryName(page, pages.account.url, taken)).not.toBe('saved');
		await expect(handleError(page)).toBeVisible();
		expect(await handleOf(site, email)).toBe('');
		expect((await site.user(owner)).nicename).toBe(taken);
	});

	test('the shortest and longest name: the box carries them, and the server refuses outside them', async ({
		page,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_handle_min: 5, diluxone_users_handle_max: 8 });

		const { email } = await signedIn(page, site, pages.login.url, 'h-len');

		await page.goto(accountSection(pages.account.url, 'details'));
		await expect(handleBox(page)).toHaveAttribute('minlength', '5');
		await expect(handleBox(page)).toHaveAttribute('maxlength', '8');

		expect(await tryName(page, pages.account.url, 'abc')).not.toBe('saved');
		await expect(handleError(page)).toContainText('5');

		expect(await tryName(page, pages.account.url, 'abcdefghij')).not.toBe('saved');
		await expect(handleError(page)).toContainText('8');
		expect(await handleOf(site, email)).toBe('');

		// Past what the address column holds, the box stops at 50.
		await options.set({ diluxone_users_handle_max: 80 });
		await page.goto(accountSection(pages.account.url, 'details'));
		await expect(handleBox(page)).toHaveAttribute('maxlength', '50');
	});

	test('spaces become dashes, or are refused when the site says so — and the note under the box says which', async ({
		page,
		site,
		pages,
		options,
	}) => {
		const { email } = await signedIn(page, site, pages.login.url, 'h-spaces');
		const tail = Date.now().toString(36);

		await page.goto(accountSection(pages.account.url, 'details'));

		const note = page.locator('.diluxone-users-handle-field .diluxone-users-note');
		const dashNote = await note.innerText();

		expect(await tryName(page, pages.account.url, `ada love ${tail}`)).toBe('saved');
		expect(await handleOf(site, email)).toBe(`ada-love-${tail}`);

		await options.set({ diluxone_users_handle_spaces: 'reject' });
		await page.goto(accountSection(pages.account.url, 'details'));
		expect(await note.innerText(), 'the note changes with the rule').not.toBe(dashNote);

		expect(await tryName(page, pages.account.url, `grace hopper ${tail}`)).not.toBe('saved');
		await expect(handleError(page)).toBeVisible();
		expect(await handleOf(site, email)).toBe(`ada-love-${tail}`);
	});

	test('an e-mail address, and nothing at all, are refused', async ({ page, site, pages }) => {
		const name = `had-${Date.now().toString(36)}`;
		const { email } = await signedIn(page, site, pages.login.url, 'h-email', { meta: { diluxone_users_handle: name } });

		expect(await tryName(page, pages.account.url, 'someone@example.org')).not.toBe('saved');
		await expect(handleError(page)).toBeVisible();

		const emailReason = await handleError(page).innerText();

		expect(await tryName(page, pages.account.url, '')).not.toBe('saved');
		await expect(handleError(page)).toBeVisible();
		expect(await handleError(page).innerText(), 'a different reason for each').not.toBe(emailReason);

		expect(await handleOf(site, email), 'the name they had stays').toBe(name);
	});

	test('changed recently: the box is locked with no Save, and a change posted anyway is refused', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_handle_cooldown: 30 });

		const now = Math.floor(Date.now() / 1000);
		const { email } = await signedIn(page, site, pages.login.url, 'h-cool', {
			meta: { diluxone_users_handle: 'old-name', diluxone_users_handle_changed: String(now) },
		});

		await page.goto(accountSection(pages.account.url, 'details'));
		await expect(handleBox(page)).toBeDisabled();
		await expect(handleForm(page).locator('button[type="submit"]')).toHaveCount(0);
		await expect(page.locator('[data-diluxone-users-handle-check]')).toHaveCount(0);

		const answer = await postByHand(page, {
			action: 'diluxone_users_handle',
			_wpnonce: await nonceOf(page, 'diluxone_users_handle'),
			diluxone_users_handle: `new-${Date.now().toString(36)}`,
		});

		expect(answer.state).not.toBe('saved');
		expect(await handleOf(site, email)).toBe('old-name');

		await page.reload();
		await expect(handleError(page), 'and the account says why').toBeVisible();
	});

	test('switched off: no form, and a name posted by hand writes nothing', async ({ page, site, pages, options }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'h-off');

		await page.goto(accountSection(pages.account.url, 'details'));

		const nonce = await nonceOf(page, 'diluxone_users_handle');

		await options.set({ diluxone_users_handle_enabled: 0 });
		await page.reload();
		await expect(handleForm(page)).toHaveCount(0);

		const answer = await postByHand(page, { action: 'diluxone_users_handle', _wpnonce: nonce, diluxone_users_handle: `ghost-${Date.now().toString(36)}` });

		expect(answer.state).not.toBe('saved');
		expect(await handleOf(site, email)).toBe('');
	});

	test('with “sign in with it” off, a public name typed in the sign-in box sends no link', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'h-nologin');
		const name = `nolog-${Date.now().toString(36)}`;

		expect(await tryName(page, pages.account.url, name)).toBe('saved');

		await page.context().clearCookies();
		await site.clearMail();
		await page.goto(pages.login.url);
		await openWay(page, 'email');
		await linkForm(page).evaluate((form: HTMLFormElement) => {
			form.noValidate = true;
		});
		await emailField(page).fill(name);
		await Promise.all([page.waitForLoadState('domcontentloaded'), page.waitForEvent('framenavigated'), linkForm(page).locator('button[type="submit"]').click()]);

		// The mail goes out inside the request the form made: it is there now or never.
		expect(await site.mail(email), 'no link went to the account behind the name').toEqual([]);
	});

	test('[diluxone_users_handle] on a page of its own saves and lands on the account details', async ({ page, site, pages }) => {
		const slug = 'my-account-handle';
		const piece = await site.page(slug, '[diluxone_users_handle]');

		try {
			const { email } = await signedIn(page, site, pages.login.url, 'h-piece');
			const name = `piece-${Date.now().toString(36)}`;

			await page.goto(piece.url);
			await handleBox(page).fill(name);
			expect(await send(page, handleForm(page).locator('button[type="submit"]'))).toBe('saved');

			expect(page.url()).toContain(accountSection(pages.account.url, 'details'));
			expect(await handleOf(site, email)).toBe(name);
		} finally {
			await site.forgetPage(slug);
		}
	});
});

test.describe('Public name: the box as it is typed in', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({ diluxone_users_handle_enabled: 1, diluxone_users_handle_cooldown: 0, diluxone_users_handle_min: 3, diluxone_users_handle_max: 50 });
	});

	const isCheck = (r: { url(): string; request(): { postData(): string | null } }) =>
		r.url().includes('admin-ajax.php') && (r.request().postData() ?? '').includes('diluxone_users_handle_check');

	test('the preview shows the address the typing turns into, and hides when the box is empty', async ({ page, site, pages }) => {
		await signedIn(page, site, pages.login.url, 'h-preview');
		await page.goto(accountSection(pages.account.url, 'details'));

		const preview = page.locator('[data-diluxone-users-handle-preview]');
		const url = page.locator('[data-diluxone-users-handle-url]');

		await page.locator('#diluxone-users-handle').fill('Ádá Love');
		await expect(url).toHaveText(/\/ada-love\/$/);
		await expect(preview).toBeVisible();

		await page.locator('#diluxone-users-handle').fill('');
		await expect(preview).toBeHidden();
	});

	test('the check link asks at once; the own name and a too-short one each get their reason', async ({ page, site, pages }) => {
		const name = `mine-${Date.now().toString(36)}`;

		await signedIn(page, site, pages.login.url, 'h-reasons', { meta: { diluxone_users_handle: name } });
		await page.goto(accountSection(pages.account.url, 'details'));

		const box = page.locator('#diluxone-users-handle');
		const notice = page.locator('[data-diluxone-users-handle-notice]');

		// Typed and then the link pressed: the question goes before the pause is over.
		await box.fill('ab');

		const started = Date.now();

		await Promise.all([page.waitForResponse(isCheck), page.locator('[data-diluxone-users-handle-check]').click()]);
		expect(Date.now() - started, 'asked at the click, not after the typing pause').toBeLessThan(600);
		await expect(notice).toHaveClass(/is-taken/);

		const shortReason = await notice.innerText();

		// The own name, written differently: free, and said to be the one they have.
		await Promise.all([page.waitForResponse(isCheck), box.fill(name.toUpperCase())]);
		await expect(notice).toHaveClass(/is-free/);
		expect(await notice.innerText()).not.toBe(shortReason);

		const ownReason = await notice.innerText();

		await Promise.all([page.waitForResponse(isCheck), box.fill(`free-${Date.now().toString(36)}`)]);
		await expect(notice).toHaveClass(/is-free/);
		expect(await notice.innerText(), 'a free name is not "the one you have now"').not.toBe(ownReason);
	});

	test('typing back the saved name clears the notice and asks nothing', async ({ page, site, pages }) => {
		await signedIn(page, site, pages.login.url, 'h-back');
		await page.goto(accountSection(pages.account.url, 'details'));

		const box = page.locator('#diluxone-users-handle');
		const notice = page.locator('[data-diluxone-users-handle-notice]');
		const initial = await box.inputValue();

		await Promise.all([page.waitForResponse(isCheck), box.fill(`other-${Date.now().toString(36)}`)]);
		await expect(notice).not.toHaveText('');

		let asked = 0;

		page.on('request', (request) => {
			if (request.url().includes('admin-ajax.php') && (request.postData() ?? '').includes('diluxone_users_handle_check')) {
				asked++;
			}
		});

		await box.fill(initial);
		await expect(notice).toHaveText('');

		// The pause the script waits before asking, and a little more, with nothing asked.
		await page.waitForTimeout(900); // the absence of a request can only be observed over time
		expect(asked).toBe(0);
	});

	test('when the check cannot be answered, the box says it could not check', async ({ page, site, pages }) => {
		await signedIn(page, site, pages.login.url, 'h-fail');
		await page.goto(accountSection(pages.account.url, 'details'));

		// The answer is held until the test has read what the box says while it waits.
		let release: () => void = () => undefined;
		const held = new Promise<void>((resolve) => {
			release = resolve;
		});

		await page.route('**/admin-ajax.php', async (route) => {
			if (!(route.request().postData() ?? '').includes('diluxone_users_handle_check')) {
				return route.continue();
			}

			await held;
			await route.fulfill({ status: 500, body: 'nope' });
		});

		const notice = page.locator('[data-diluxone-users-handle-notice]');

		await page.locator('#diluxone-users-handle').fill(`x-${Date.now().toString(36)}`);
		await page.locator('[data-diluxone-users-handle-check]').click();
		await expect(notice).toHaveClass(/is-waiting/);

		const checking = await notice.innerText();

		expect(checking).not.toBe('');
		release();

		// "Checking…" while it asks, then the failure in its place.
		await expect(notice).not.toHaveText(checking);
		await expect(notice).toHaveClass(/is-waiting/);
		expect(await notice.innerText()).not.toBe('');
	});
});
