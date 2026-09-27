import { APIRequestContext, expect, request as playwrightRequest } from '@playwright/test';

/**
 * The way a test talks to the site about things a browser cannot see.
 *
 * Everything here goes through the e2e mu-plugin's REST routes: the mailbox,
 * the settings, the accounts, and what the fake social network will say. It is
 * one class so a spec never builds a URL or remembers the header.
 */

export const E2E_NS = '/wp-json/diluxone-e2e/v1';
export const E2E_HEADER = { 'X-Diluxone-E2E': 'diluxone-e2e' };

/** The e-mail domain every account the suite makes belongs to. */
export const E2E_DOMAIN = '@e2e.test';

/** The path of a site's address without its trailing slash: `/alpha`, or `` at the root. */
export function sitePrefix(url: string): string {
	return new URL(url).pathname.replace(/\/+$/, '');
}

/** An address nobody has used yet, so a test never inherits another's state. */
export function freshEmail(prefix = 'p'): string {
	return `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}${E2E_DOMAIN}`;
}

export interface Mail {
	to: string;
	subject: string;
	body: string;
	sent: number;
}

export interface SeedPages {
	pages: Record<'login' | 'register' | 'account', { id: number; url: string }>;
	home: string;
}

/** What an option was before a test changed it. `null` means it was not there. */
export type OptionBag = Record<string, unknown>;

export class Site {
	/**
	 * @param prefix The path the site lives under, for a site of a
	 *               subdirectory network (`/alpha`). Empty for a site at the
	 *               root. It is needed because every route here starts with a
	 *               slash, and a path that starts with a slash throws away the
	 *               path of the base URL it is resolved against.
	 */
	constructor(
		private readonly api: APIRequestContext,
		private readonly prefix = ''
	) {}

	/** For a spec that has no `request` fixture handy (global setup/teardown). */
	static async open(baseURL: string): Promise<Site> {
		return new Site(await playwrightRequest.newContext({ baseURL }), sitePrefix(baseURL));
	}

	private async call(method: 'get' | 'post' | 'delete', path: string, body?: unknown): Promise<any> {
		const send = () =>
			this.api[method](`${this.prefix}${E2E_NS}${path}`, {
				headers: E2E_HEADER,
				...(body === undefined ? {} : { data: body }),
			});

		// Once more on a socket Apache closed under us. A context kept for a
		// whole test sits idle while the browser does its part, longer than
		// Apache's keep-alive, and the next call is written into a connection
		// the server already hung up. Every route here is idempotent, so
		// sending it again is the same request, not a second one.
		const response = await send().catch((error: Error) => {
			if (/socket hang up|ECONNRESET/.test(error.message)) {
				return send();
			}

			throw error;
		});

		expect(
			response.ok(),
			`${method.toUpperCase()} ${path} answered ${response.status()}: ${await response.text()}`
		).toBeTruthy();

		return response.json();
	}

	/** The pages the plugin's shortcodes live on, made if they were not there. */
	seed(): Promise<SeedPages> {
		return this.call('post', '/seed');
	}

	/**
	 * Writes settings and hands back what they were.
	 *
	 * Keep the answer and pass it back to setOptions() at the end: that is the
	 * whole contract that leaves the environment as it was found. A value of
	 * `null` deletes the option, which is how an absent one is restored.
	 */
	async setOptions(set: OptionBag, extra: { flush?: boolean; forgetTransients?: boolean } = {}): Promise<OptionBag> {
		const answer = await this.call('post', '/options', {
			set,
			flush: extra.flush ?? false,
			forget_transients: extra.forgetTransients ?? false,
		});

		return answer.previous as OptionBag;
	}

	getOptions(keys: string[]): Promise<OptionBag> {
		return this.call('get', `/options?keys=${encodeURIComponent(keys.join(','))}`);
	}

	/** Every message the site tried to send to this address, newest last. */
	mail(to: string): Promise<Mail[]> {
		return this.call('get', `/mail?to=${encodeURIComponent(to)}`);
	}

	clearMail(): Promise<unknown> {
		return this.call('delete', '/mail');
	}

	user(email: string, fields: string[] = []): Promise<any> {
		const extra = fields.length ? `&fields=${encodeURIComponent(fields.join(','))}` : '';

		return this.call('get', `/user?email=${encodeURIComponent(email)}${extra}`);
	}

	makeUser(user: {
		email: string;
		password?: string;
		role?: string;
		name?: string;
		meta?: Record<string, unknown>;
	}): Promise<{ id: number; email: string; password: string; totp: string }> {
		return this.call('post', '/user', user);
	}

	deleteUser(email: string): Promise<unknown> {
		return this.call('delete', `/user?email=${encodeURIComponent(email)}`);
	}

	/** Everything the suite ever created, gone. Used by the teardown. */
	deleteE2EUsers(): Promise<unknown> {
		return this.call('delete', `/user?domain=${encodeURIComponent(E2E_DOMAIN)}`);
	}

	/** What the fake network answers on its profile endpoint. */
	setIdentity(identity: Record<string, unknown>): Promise<unknown> {
		return this.call('post', '/identity', { identity });
	}

	/** Moves a deadline into the past: 'link', '2fa' or 'resend'. */
	expire(email: string, what: 'link' | '2fa' | 'resend'): Promise<unknown> {
		return this.call('post', '/expire', { email, what });
	}
}

/**
 * Waits for a message and hands back the newest one.
 *
 * Polling and not a fixed wait: the send happens inside the request the form
 * made, so it is normally there on the first look, and on a slow machine it is
 * there on the second.
 */
export async function waitForMail(
	site: Site,
	to: string,
	options: { subject?: RegExp; after?: number; timeout?: number } = {}
): Promise<Mail> {
	const deadline = Date.now() + (options.timeout ?? 10_000);
	let seen: Mail[] = [];

	while (Date.now() < deadline) {
		seen = await site.mail(to);

		const matching = seen.filter(
			(one) =>
				(options.subject === undefined || options.subject.test(one.subject)) &&
				(options.after === undefined || one.sent > options.after)
		);

		if (matching.length > 0) {
			return matching[matching.length - 1];
		}

		await new Promise((resolve) => setTimeout(resolve, 200));
	}

	throw new Error(
		`No message for ${to} within the timeout. What the mailbox holds: ${JSON.stringify(seen, null, 2)}`
	);
}

/** The one link in a message body, or the first one matching a pattern. */
export function linkIn(mail: Mail, pattern = /https?:\/\/\S+/): string {
	const found = mail.body.match(pattern);

	if (!found) {
		throw new Error(`No link in the message.\n${mail.body}`);
	}

	// WordPress wraps its own links in angle brackets, and a sentence
	// ending puts a full stop against the last character of the address.
	return found[0].replace(/[>).,]+$/, '');
}

/** The six-digit code a second-step message carries. */
export function codeIn(mail: Mail): string {
	const found = mail.body.match(/\b(\d{6})\b/);

	if (!found) {
		throw new Error(`No six-digit code in the message.\n${mail.body}`);
	}

	return found[1];
}
