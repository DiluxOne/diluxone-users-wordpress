import { deflateSync } from 'node:zlib';
import { APIRequestContext, Browser, Frame, Locator, Page } from '@playwright/test';
import { expect, expectSignedIn } from './fixtures';
import { E2E_HEADER, E2E_NS, Site, freshEmail } from './api';
import { adminUrl, navigated, signInWithPassword, submitPluginForm } from './ui';

/**
 * What the specs about the content screens share: the Account area, User
 * fields, Design and E-mail notices, and what each of them changes where
 * people are.
 *
 * Everything here points at a name, an id, a class or a state in the address,
 * never at a sentence: the single site runs in Spanish, the network in
 * English, and the plugin ships eight locales.
 */

export const MEMBER_PASSWORD = 'e2e-Content-1!';

/** The two fields a fresh site starts with: WordPress's own names. */
export const NATIVE_FIELDS = [
	{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
];

export interface StoredField {
	key: string;
	label: string;
	type: string;
	help?: string;
	placeholder?: string;
	options?: string[];
	required?: number;
	group?: string;
	active?: number;
	edit?: string;
	edit_max?: number;
}

/** A word nobody else used in this run, for labels and texts a test then looks for. */
export function unique(prefix: string): string {
	return `${prefix} ${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`;
}

/**
 * Somebody signed in with a password in the page handed over — the `guest`
 * browser, or a context of the test's own.
 */
export async function signInMember(
	page: Page,
	site: Site,
	loginUrl: string,
	prefix: string,
	extra: { role?: string; meta?: Record<string, unknown> } = {}
): Promise<string> {
	const email = freshEmail(prefix);

	await site.makeUser({ email, password: MEMBER_PASSWORD, role: extra.role, meta: extra.meta });
	await page.context().clearCookies();
	await page.goto(loginUrl);
	await signInWithPassword(page, email, MEMBER_PASSWORD);
	await expectSignedIn(page, email);

	return email;
}

/** A browser of its own, with somebody signed in. Closed by the caller. */
export async function memberElsewhere(
	browser: Browser,
	baseURL: string,
	site: Site,
	loginUrl: string,
	prefix: string,
	extra: { role?: string; meta?: Record<string, unknown> } = {}
): Promise<{ email: string; page: Page; close: () => Promise<void> }> {
	const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
	const page = await context.newPage();
	const email = await signInMember(page, site, loginUrl, prefix, extra);

	return { email, page, close: () => context.close() };
}

/* ── User fields ───────────────────────────────────────────────────── */

/** The fields as stored, read through the side door. */
export async function storedFields(site: Site): Promise<StoredField[]> {
	return ((await site.getOptions(['diluxone_users_fields'])).diluxone_users_fields ?? []) as StoredField[];
}

/** One stored field by its label. */
export async function storedField(site: Site, label: string): Promise<StoredField | undefined> {
	return (await storedFields(site)).find((one) => one.label === label);
}

/** The form a field is edited in, on its own screen or in the dialog. */
export function fieldForm(page: Page): Locator {
	return page.locator('form.diluxone-users-form-admin');
}

/** Presses the field form's own button and waits for the list's answer. */
export async function submitField(page: Page): Promise<string> {
	await navigated(page, () => fieldForm(page).locator('#submit').click());

	return new URL(page.url()).searchParams.get('diluxone_users_done') ?? '';
}

export interface NewField {
	label: string;
	type?: string;
	required?: boolean;
	active?: boolean;
	group?: 'main' | 'extra';
	help?: string;
	placeholder?: string;
	/** One per line, for a fixed list or a text with suggestions. */
	values?: string[];
	/** Countries shown first. */
	preferred?: string[];
	/** The dial code a phone comes with. */
	dial?: string;
	edit?: 'always' | 'limited' | 'never';
	editMax?: number;
}

/**
 * Fills the field form with what is given, touching nothing else.
 *
 * The tick boxes and the round choices of the design system are drawn over
 * the real input, which is why they are set with `force`.
 */
export async function fillField(page: Page, field: Partial<NewField>): Promise<void> {
	const form = fieldForm(page);

	if (field.label !== undefined) {
		await form.locator('[name="diluxone_users_field[label]"]').fill(field.label);
	}

	if (field.type !== undefined) {
		await form.locator('#diluxone-users-type').selectOption(field.type);
	}

	if (field.group !== undefined) {
		await form.locator('[name="diluxone_users_field[group]"]').selectOption(field.group);
	}

	if (field.help !== undefined) {
		await form.locator('[name="diluxone_users_field[help]"]').fill(field.help);
	}

	if (field.placeholder !== undefined) {
		await form.locator('[name="diluxone_users_field[placeholder]"]').fill(field.placeholder);
	}

	if (field.values !== undefined) {
		await form.locator('[name="diluxone_users_field[options]"]').fill(field.values.join('\n'));
	}

	if (field.preferred !== undefined) {
		await form.locator('[name="diluxone_users_field[preferred][]"]').selectOption(field.preferred);
	}

	if (field.dial !== undefined) {
		await form.locator('[name="diluxone_users_field[default_country]"]').selectOption(field.dial);
	}

	if (field.edit !== undefined) {
		await form.locator(`input[name="diluxone_users_field[edit]"][value="${field.edit}"]`).check({ force: true });
	}

	if (field.editMax !== undefined) {
		await form.locator('[name="diluxone_users_field[edit_max]"]').fill(String(field.editMax));
	}

	if (field.required !== undefined) {
		await form.locator('input[type="checkbox"][name="diluxone_users_field[required]"]').setChecked(field.required, { force: true });
	}

	if (field.active !== undefined) {
		await form.locator('input[type="checkbox"][name="diluxone_users_field[active]"]').setChecked(field.active, { force: true });
	}
}

/**
 * Adds a field through the screen the way an administrator does, and hands
 * back what was stored.
 */
export async function addField(page: Page, site: Site, field: NewField): Promise<StoredField> {
	await page.goto(adminUrl('diluxone-users-fields') + '&diluxone_users_new=1');
	await fillField(page, field);

	expect(await submitField(page), `the field “${field.label}” is saved`).toBe('saved');

	const stored = await storedField(site, field.label);

	expect(stored, `the field “${field.label}” is on the list`).toBeTruthy();

	return stored!;
}

/** Opens one field's own screen and saves what `change` filled in. */
export async function editField(page: Page, key: string, change: Partial<NewField>): Promise<string> {
	await page.goto(adminUrl('diluxone-users-fields') + `&field=${key}`);
	await fillField(page, change);

	return submitField(page);
}

/** The fields form on a page: the account's details, or the shortcode's. */
export function fieldsForm(page: Page): Locator {
	return page.locator('form.diluxone-users-form').filter({ has: page.locator('input[name="action"][value="diluxone_users_fields_save"]') });
}

/**
 * Saves the fields form with whatever is in it, the browser's own checks
 * switched off: the server is the rule, and a value the browser would stop is
 * exactly the value a hand-made request sends.
 */
export async function saveFieldsForm(page: Page): Promise<string> {
	const form = fieldsForm(page);

	await form.evaluate((element: HTMLFormElement) => {
		element.noValidate = true;
	});

	return submitPluginForm(page, form);
}

/**
 * Puts a value in a control as a hand-made request would: the browser's
 * type, `readonly`, `disabled` and the list of choices all out of the way.
 */
export async function forceValue(page: Page, name: string, value: string): Promise<void> {
	await page.locator(`[name="${name}"]`).first().evaluate((element: HTMLInputElement | HTMLSelectElement, wanted: string) => {
		element.removeAttribute('readonly');
		element.removeAttribute('disabled');

		if (element instanceof HTMLSelectElement) {
			if (![...element.options].some((option) => option.value === wanted)) {
				element.add(new Option(wanted, wanted));
			}
		} else if (element.type !== 'checkbox') {
			element.type = 'text';
		}

		element.value = wanted;
	}, value);
}

/* ── The dashboard's own messages ──────────────────────────────────── */

/** The notice the design system draws in a screen's rail, by its kind. */
export function railNotice(page: Page, kind: 'warning' | 'info' | 'error' | 'success' = 'warning'): Locator {
	return page.locator(`.du-notice--${kind}`);
}

/** A colour as the browser reports it, from a `#rrggbb`. */
export function rgb(hex: string): string {
	const n = parseInt(hex.replace('#', ''), 16);

	return `rgb(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255})`;
}

/** A custom property as a page (or the stage's frame) resolves it, through a probe. */
export async function resolvedColour(where: Page | Frame, property: string): Promise<string> {
	return where.evaluate((name: string) => {
		const probe = document.createElement('span');

		probe.style.color = `var(${name})`;
		document.body.appendChild(probe);

		const colour = getComputedStyle(probe).color;

		probe.remove();

		return colour;
	}, property);
}

/** The frame of the stage beside a Design tab's settings, loaded. */
export async function stage(page: Page): Promise<Frame> {
	const element = await page.locator('.diluxone-users-studio__preview iframe.diluxone-users-stage__frame').first().elementHandle();
	const frame = await element!.contentFrame();

	await frame!.waitForLoadState('load');

	return frame!;
}

/** Waits for the stage to have been drawn again from the form. */
export function previewAnswer(page: Page) {
	return page.waitForResponse((response) => response.url().includes('admin-ajax.php') && (response.request().postData() ?? '').includes('diluxone_users_preview'));
}

/* ── The mu-plugin's own routes for these specs ────────────────────── */

async function sideDoor(request: APIRequestContext, prefix: string, method: 'post' | 'delete', path: string, data?: unknown): Promise<any> {
	const response = await request[method](`${prefix}${E2E_NS}${path}`, { headers: E2E_HEADER, ...(data === undefined ? {} : { data }) });

	expect(response.ok(), `${method} ${path} answered ${response.status()}`).toBeTruthy();

	return response.json();
}

/**
 * Writes a person's meta without touching their password, which would end
 * the session they are signed in with.
 *
 * @param prefix The site's path on a subdirectory network (`/alpha`), '' at the root.
 */
export function setUserMeta(request: APIRequestContext, email: string, meta: Record<string, unknown>, prefix = ''): Promise<unknown> {
	return sideDoor(request, prefix, 'post', '/h4/usermeta', { email, meta });
}

/** A known picture in the media library, made once and reused. */
export function mediaPicture(request: APIRequestContext, name = 'e2e-picture', prefix = ''): Promise<{ id: number; url: string }> {
	return sideDoor(request, prefix, 'post', '/h4/media', { name });
}

/** That picture, removed from the library. */
export function forgetPicture(request: APIRequestContext, name = 'e2e-picture', prefix = ''): Promise<unknown> {
	return sideDoor(request, prefix, 'delete', `/h4/media?name=${encodeURIComponent(name)}`);
}

/**
 * Picks a picture for an image field through WordPress's media library, the
 * way a person does: the button, the picture in the grid, the select button.
 *
 * @param name The name of the hidden input the field stores the id in.
 */
export async function pickPicture(page: Page, name: string, picture: { id: number }): Promise<Locator> {
	const field = page.locator('[data-diluxone-users-image]').filter({ has: page.locator(`input[name="${name}"]`) });

	await field.locator('[data-diluxone-users-image-pick]').click();

	const modal = page.locator('.media-modal:visible');

	await expect(modal).toBeVisible();
	await modal.locator(`li.attachment[data-id="${picture.id}"]`).click();
	await modal.locator('.media-button-select').click();
	await expect(modal).toBeHidden();
	await expect(field.locator(`input[name="${name}"]`)).toHaveValue(String(picture.id));

	return field;
}

/**
 * A real PNG of a given side, filled with noise so it does not compress:
 * a 48-pixel one is a few kilobytes, which is what a size limit is tried with.
 */
export function noisyPng(side: number): Buffer {
	const table = Array.from({ length: 256 }, (_, n) => {
		let c = n;

		for (let k = 0; k < 8; k++) {
			c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
		}

		return c >>> 0;
	});
	const crc = (bytes: Buffer) => {
		let c = 0xffffffff;

		for (const byte of bytes) {
			c = table[(c ^ byte) & 0xff] ^ (c >>> 8);
		}

		return (c ^ 0xffffffff) >>> 0;
	};
	const chunk = (type: string, data: Buffer) => {
		const length = Buffer.alloc(4);
		const sum = Buffer.alloc(4);
		const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);

		length.writeUInt32BE(data.length);
		sum.writeUInt32BE(crc(body));

		return Buffer.concat([length, body, sum]);
	};
	const header = Buffer.alloc(13);

	header.writeUInt32BE(side, 0);
	header.writeUInt32BE(side, 4);
	header[8] = 8; // bit depth
	header[9] = 2; // truecolour
	const rows = Buffer.alloc((side * 3 + 1) * side);

	for (let i = 0; i < rows.length; i++) {
		rows[i] = i % (side * 3 + 1) === 0 ? 0 : Math.floor(Math.random() * 256);
	}

	return Buffer.concat([
		Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
		chunk('IHDR', header),
		chunk('IDAT', deflateSync(rows)),
		chunk('IEND', Buffer.alloc(0)),
	]);
}
