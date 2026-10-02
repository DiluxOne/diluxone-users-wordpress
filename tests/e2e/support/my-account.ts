import { Browser, Locator, Page, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { deflateSync } from 'node:zlib';
import { Site, freshEmail } from './api';
import { expectSignedIn, stateOf } from './fixtures';
import { signInWithPassword } from './ui';

/**
 * What the account-area specs (`my-account-*.spec.ts`) share.
 *
 * Signing somebody in, opening the box a control lives in, pressing a form's
 * button and reading the state its redirect carries, posting a form by hand
 * with the nonce the page handed out, and the dev site's WP-CLI. Nothing here
 * reads a sentence: the site is in Spanish and the plugin ships eight locales.
 */

export const PASSWORD = 'e2e-MyAccount-1!';

/** A PNG of one pixel: a real image for the media library. */
export const PIXEL = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkaPhfDwAEmwH/8d3cZQAAAABJRU5ErkJggg==',
	'base64'
);

/** A CRC-32, for the chunks of a PNG made here. */
function crc32(buffer: Buffer): number {
	let crc = ~0;

	for (const byte of buffer) {
		crc ^= byte;

		for (let bit = 0; bit < 8; bit++) {
			crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1));
		}
	}

	return ~crc >>> 0;
}

function chunk(type: string, data: Buffer): Buffer {
	const length = Buffer.alloc(4);
	const crc = Buffer.alloc(4);
	const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);

	length.writeUInt32BE(data.length);
	crc.writeUInt32BE(crc32(body));

	return Buffer.concat([length, body, crc]);
}

/**
 * A real grey PNG of a given size, optionally carrying `padding` bytes of text.
 *
 * The size is what the "6000 pixels a side" rule reads; the padding is what
 * makes a picture heavier than a limit in KB without being any bigger to look
 * at. Both are honest PNGs that getimagesize() and the media library accept.
 */
export function png(width: number, height: number, padding = 0): Buffer {
	const header = Buffer.alloc(13);

	header.writeUInt32BE(width, 0);
	header.writeUInt32BE(height, 4);
	header[8] = 8; // bit depth
	header[9] = 0; // greyscale
	header[10] = 0;
	header[11] = 0;
	header[12] = 0;

	// One filter byte per row, then one byte per pixel.
	const raw = Buffer.alloc((width + 1) * height, 0x80);

	for (let row = 0; row < height; row++) {
		raw[row * (width + 1)] = 0;
	}

	const parts = [
		Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
		chunk('IHDR', header),
		...(padding > 0 ? [chunk('tEXt', Buffer.concat([Buffer.from('Comment\0'), Buffer.alloc(padding, 0x61)]))] : []),
		chunk('IDAT', deflateSync(raw)),
		chunk('IEND', Buffer.alloc(0)),
	];

	return Buffer.concat(parts);
}

/** Somebody new, signed in with a password in this browser. */
export async function signedIn(
	page: Page,
	site: Site,
	loginUrl: string,
	prefix: string,
	extra: { role?: string; meta?: Record<string, unknown>; name?: string } = {}
): Promise<{ email: string; id: number }> {
	const email = freshEmail(prefix);
	const made = await site.makeUser({ email, password: PASSWORD, ...extra });

	await page.goto(loginUrl);
	await signInWithPassword(page, email, PASSWORD);
	await expectSignedIn(page, email);

	return { email, id: made.id };
}

/** The same person in a browser of their own: another session, another device. */
export async function anotherBrowser(
	browser: Browser,
	baseURL: string | undefined,
	email: string,
	loginUrl: string,
	userAgent?: string
): Promise<Page> {
	const context = await browser.newContext({ baseURL, storageState: undefined, ...(userAgent ? { userAgent } : {}) });
	const page = await context.newPage();

	await page.goto(loginUrl);
	await signInWithPassword(page, email, PASSWORD);
	await expectSignedIn(page, email);

	return page;
}

/**
 * Brings something on an account screen into view, opening only the closed
 * boxes around it.
 */
export async function reveal(page: Page, inner: string): Promise<void> {
	const target = page.locator(inner).first();

	await expect(target).toBeAttached();

	const closed = page.locator('details:not([open])').filter({ has: page.locator(inner) });

	for (let n = await closed.count(); n > 0; n = await closed.count()) {
		await closed.first().locator('> summary').click();
	}

	await expect(target).toBeVisible();
}

/** Presses a button and waits for the redirect's `diluxone-users=` answer. */
export async function send(page: Page, button: Locator): Promise<string> {
	await Promise.all([page.waitForURL(/[?&]diluxone[-_]users/, { waitUntil: 'domcontentloaded' }), button.click()]);

	return stateOf(page.url());
}

/** The plugin form that posts a given `action`. */
export function formOf(page: Page, action: string): Locator {
	return page.locator('form').filter({ has: page.locator(`input[name="action"][value="${action}"]`) });
}

/** The nonce a plugin form carries. */
export async function nonceOf(page: Page, action: string): Promise<string> {
	return formOf(page, action).first().locator('input[name="_wpnonce"]').inputValue();
}

/**
 * Posts to admin-post.php by hand from this browser's session, and answers
 * with the status and where it was sent.
 */
export async function postByHand(
	page: Page,
	form: Record<string, string>,
	extra: { multipart?: Record<string, string | { name: string; mimeType: string; buffer: Buffer }>; url?: string } = {}
): Promise<{ status: number; location: string; state: string; body: string }> {
	const url = extra.url ?? '/wp-admin/admin-post.php';
	const response = await page.request.post(url, {
		...(extra.multipart ? { multipart: { ...form, ...extra.multipart } } : { form }),
		headers: { referer: page.url() },
		maxRedirects: 0,
		failOnStatusCode: false,
	});
	const location = response.headers().location ?? '';

	return {
		status: response.status(),
		location,
		state: location ? stateOf(new URL(location, page.url()).href) : '',
		body: await response.text(),
	};
}

/** WP-CLI on the dev site (the single-site suite's target). */
export function devWp(args: string[]): string {
	return execFileSync('npx', ['wp-env', 'run', 'cli', 'wp', ...args], {
		encoding: 'utf8',
		stdio: ['ignore', 'pipe', 'pipe'],
		env: { ...process.env, WP_ENV_DEBUG: '' },
	})
		.split('\n')
		.filter((line) => !/^[ℹ✔✖⚠]|^- |^Starting |^Ran `/.test(line))
		.join('\n')
		.trim();
}

/**
 * A WP-CLI command that is expected to fail, on either container.
 *
 * @returns What it printed, stdout and stderr together, and whether it failed.
 */
export function wpFails(container: 'cli' | 'tests-cli', args: string[]): { failed: boolean; out: string } {
	try {
		const out = execFileSync('npx', ['wp-env', 'run', container, 'wp', ...args], {
			encoding: 'utf8',
			stdio: ['ignore', 'pipe', 'pipe'],
			env: { ...process.env, WP_ENV_DEBUG: '' },
		});

		return { failed: false, out };
	} catch (error) {
		const failure = error as { stdout?: string; stderr?: string };

		return { failed: true, out: `${failure.stdout ?? ''}\n${failure.stderr ?? ''}` };
	}
}
