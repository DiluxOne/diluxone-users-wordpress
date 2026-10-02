import { APIRequestContext, Browser, BrowserContext, CDPSession, Page, expect } from '@playwright/test';
import { E2E_HEADER, E2E_NS, sitePrefix } from './api';

/**
 * What the specs about the ways in share (`signin-*.spec.ts`, single site and
 * network): a side door on a person's stored meta, the fake network's
 * credentials, the virtual passkey and the few steps every one of them takes.
 */

/** The fake network, configured, tested and on. */
export const MOCK_ON = { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } };

/** The same network with its credentials kept but switched off. */
export const MOCK_OFF = { mock: { active: 0, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } };

/** A laptop with a fingerprint reader, as Chrome's virtual authenticator. */
export const VIRTUAL_KEY = {
	protocol: 'ctap2' as const,
	transport: 'internal' as const,
	hasResidentKey: true,
	hasUserVerification: true,
	isUserVerified: true,
	automaticPresenceSimulation: true,
};

/**
 * A person's meta as stored, read and written through the mu-plugin's
 * `/signin/meta` route — arrays as arrays, and nothing else of the account
 * touched (the `/user` write sets the password again).
 */
export class Meta {
	constructor(
		private readonly api: APIRequestContext,
		private readonly baseURL = ''
	) {}

	private url(query = ''): string {
		return `${this.baseURL ? sitePrefix(this.baseURL) : ''}${E2E_NS}/signin/meta${query}`;
	}

	async get(email: string, keys: string[]): Promise<Record<string, any>> {
		const response = await this.api.get(
			this.url(`?email=${encodeURIComponent(email)}&keys=${encodeURIComponent(keys.join(','))}`),
			{ headers: E2E_HEADER }
		);

		expect(response.ok(), await response.text()).toBeTruthy();

		return response.json();
	}

	/** Writes values (null deletes) and moves deadlines into the past (`key` or `key.field`). */
	async set(email: string, set: Record<string, unknown> = {}, past: string[] = []): Promise<void> {
		const response = await this.api.post(this.url(), { headers: E2E_HEADER, data: { email, set, past } });

		expect(response.ok(), await response.text()).toBeTruthy();
	}
}

/** Presses the second-step form's button and waits for the answer to land. */
export async function answerChallenge(page: Page, code: string): Promise<void> {
	await page.locator('input[name="diluxone_users_2fa_code"]').fill(code);
	await Promise.all([
		page.waitForEvent('framenavigated', (frame) => frame === page.mainFrame()),
		page.locator('.diluxone-users-login--2fa form.diluxone-users-form button[type="submit"]').first().click(),
	]);
	await page.waitForLoadState('domcontentloaded');
}

/** A virtual authenticator on this page, and the CDP session that holds it. */
export async function virtualKey(page: Page): Promise<{ cdp: CDPSession; authenticatorId: string }> {
	const cdp = await page.context().newCDPSession(page);

	await cdp.send('WebAuthn.enable', { enableUI: false });

	const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', { options: VIRTUAL_KEY });

	return { cdp, authenticatorId };
}

/**
 * Leaves for the fake network the way the button does, and hands back the
 * address the provider sends the browser back to — without opening it.
 *
 * Through the page's own request context, so the trip's cookie lands in this
 * browser exactly as a click would leave it. Not `page.route()`: a route only
 * sees the first request of a redirect chain, and the return is the third.
 */
export async function catchCallback(page: Page, start = '/sso/mock/?diluxone_users_go=1'): Promise<string> {
	const out = await page.request.get(start, { maxRedirects: 0 });

	expect(out.status(), 'the trip starts with a redirect to the provider').toBe(302);

	const authorize = out.headers()['location'] ?? '';
	expect(authorize, 'to the provider').toContain('/oauth/authorize');

	const back = await page.request.get(authorize, { maxRedirects: 0 });

	expect(back.status(), 'the provider answers with a redirect').toBe(302);

	const callback = back.headers()['location'] ?? '';
	expect(callback, 'back to the site with a code').toMatch(/\/sso\/mock\/\?(.*&)?code=/);

	return callback;
}

/** Clears only WordPress's session cookies, leaving the plugin's own (trip, trust). */
export async function dropSession(context: BrowserContext): Promise<void> {
	await context.clearCookies({ name: /^wordpress_(logged_in_|sec_)?[0-9a-f]+$/ });
}

/** A one-pixel PNG, for the settings that want a picture from the media library. */
const PIXEL = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
	'base64'
);

/**
 * An image in the media library, uploaded as the administrator through the
 * REST API, and a way to delete it again.
 */
export async function uploadImage(
	browser: Browser,
	baseURL: string,
	adminState: string
): Promise<{ id: number; url: string; forget: () => Promise<void> }> {
	const context = await browser.newContext({ baseURL, storageState: adminState });
	const page = await context.newPage();

	await page.goto('/wp-admin/');
	const nonce = await page.evaluate(() => (window as any).wpApiSettings?.nonce as string);

	const made = await page.request.post('/wp-json/wp/v2/media', {
		headers: { 'X-WP-Nonce': nonce },
		multipart: { file: { name: `e2e-signin-${Date.now()}.png`, mimeType: 'image/png', buffer: PIXEL } },
	});

	expect(made.status(), await made.text()).toBe(201);

	const json = await made.json();

	return {
		id: json.id as number,
		url: json.source_url as string,
		forget: async () => {
			await page.request.delete(`/wp-json/wp/v2/media/${json.id}?force=true`, { headers: { 'X-WP-Nonce': nonce } });
			await context.close();
		},
	};
}
