import { execFileSync } from 'node:child_process';
import { APIRequestContext, Browser, BrowserContext, expect } from '@playwright/test';
import { E2E_HEADER, E2E_NS } from './api';
import { fillCredentials } from './ui';

/**
 * What the refusal specs share: who sends a request, with which nonce, and
 * what counts as having been refused.
 *
 * A refusal has two halves and both are asserted. The answer — the status
 * WordPress or the plugin gives, or where it sends the request — and the
 * silence after it: whatever the request would have written is read back
 * through the side door and has to be what it was before. An answer of 403
 * from a handler that wrote first and refused afterwards is not a refusal.
 */

/**
 * No cookies at all, said out loud. A context made inside a spec that uses
 * the administrator's state inherits that state unless told otherwise — which
 * is how a "stranger" first turned out to be the administrator.
 */
export const NOBODY = { cookies: [], origins: [] };

/** A nonce WordPress never made: ten hex characters, the shape of a real one. */
export const FORGED = 'f0f0f0f0f0';

/** What a request was answered: the status, where it was sent, and the body. */
export interface Answer {
	status: number;
	location: string;
	body: string;
	type: string;
}

/**
 * How a refusal looks from the outside.
 *
 *   nonce      403 — check_admin_referer() / check_ajax_referer() failed
 *   forbidden  403 — "You are not allowed to do this.", or WordPress's own
 *                    refusal of a screen the role may not open
 *   signin     401 — "You have to sign in first."
 *   bad        400 — admin-post.php / admin-ajax.php with nothing hooked for
 *                    this caller (a guest, or an action this site does not have)
 *   to-login   302 — to the sign-in page (wp-login.php or the plugin's page)
 *   ignored    200 — the screen drawn again with no success notice: a panel's
 *                    save only runs behind a valid nonce, so without one the
 *                    POST is a page view
 *   loud       a signed-out post answered out loud — 400, 401 or the sign-in
 *                    page — and never a blank 200
 *   {state}    302 back with `diluxone-users=<state>`
 */
export type Outcome =
	| 'nonce'
	| 'forbidden'
	| 'signin'
	| 'bad'
	| 'to-login'
	| 'ignored'
	| 'loud'
	| { state: string; to?: RegExp }
	| { status: number; to?: RegExp };

/** Sends one request the way a forged form would, and reads the answer without following it. */
export async function send(
	api: APIRequestContext,
	method: 'GET' | 'POST',
	url: string,
	form?: Record<string, string | string[]>,
	multipart?: Record<string, string | { name: string; mimeType: string; buffer: Buffer }>
): Promise<Answer> {
	const options: Parameters<APIRequestContext['fetch']>[1] = { method, maxRedirects: 0, failOnStatusCode: false };

	if (multipart) {
		options.multipart = multipart;
	} else if (form) {
		// URLSearchParams and not `form:` — a field sent more than once
		// (`diluxone_users_order_form[]`) is a list, and `form:` takes one
		// value per name.
		const body = new URLSearchParams();

		for (const [name, value] of Object.entries(form)) {
			for (const one of Array.isArray(value) ? value : [value]) {
				body.append(name, one);
			}
		}

		options.data = body.toString();
		options.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
	}

	const response = await api.fetch(url, options);

	return {
		status: response.status(),
		location: response.headers()['location'] ?? '',
		body: await response.text(),
		type: response.headers()['content-type'] ?? '',
	};
}

/** Asserts an answer is the refusal expected, saying which request it was. */
export function expectRefused(answer: Answer, outcome: Outcome, label: string): void {
	const said = `${label} answered ${answer.status} ${answer.location}`;

	if (outcome === 'nonce' || outcome === 'forbidden') {
		expect(answer.status, said).toBe(403);
		expect(answer.location, said).toBe('');
		return;
	}

	if (outcome === 'signin') {
		expect(answer.status, said).toBe(401);
		return;
	}

	if (outcome === 'bad') {
		expect(answer.status, said).toBe(400);
		return;
	}

	if (outcome === 'to-login') {
		expect(answer.status, said).toBe(302);
		expect(answer.location, said).toMatch(/wp-login\.php|e2e-login/);
		return;
	}

	if (outcome === 'ignored') {
		expect(answer.status, said).toBe(200);
		expect(answer.body, `${said}: a success notice`).not.toContain('notice-success');
		return;
	}

	if (outcome === 'loud') {
		const loud =
			answer.status === 400 || answer.status === 401 || (answer.status === 302 && /wp-login\.php|e2e-login/.test(answer.location));

		expect(
			loud,
			`bug: ${said} — a signed-out post to a handler that needs a session is answered with a blank ${answer.status}. ` +
				'The hub redirect (includes/network-hub.php, diluxone_users_post_to_hub) is hooked on admin_post_nopriv_* on every site, ' +
				'so WordPress runs it instead of answering 400, it does nothing where there is no hub to go to, and the handler’s own ' +
				'signed-out answer (401 or the sign-in page) is never reached.'
		).toBe(true);
		return;
	}

	if ('state' in outcome) {
		expect(answer.status, said).toBe(302);
		expect(new URL(answer.location, 'http://x').searchParams.get('diluxone-users'), said).toBe(outcome.state);

		if (outcome.to) {
			expect(answer.location, said).toMatch(outcome.to);
		}

		return;
	}

	expect(answer.status, said).toBe(outcome.status);

	if (outcome.to) {
		expect(answer.location, said).toMatch(outcome.to);
	}
}

/**
 * A nonce that is valid for whoever this context is signed in as.
 *
 * Made by the mu-plugin from the session cookie the request carries, so it is
 * the nonce that person's own page would hold: the refusal that follows is the
 * capability's, not the nonce's.
 */
export async function nonceFor(api: APIRequestContext, action: string, prefix = ''): Promise<{ user: number; nonce: string }> {
	const response = await api.get(`${prefix}${E2E_NS}/refusals/nonce?action=${encodeURIComponent(action)}`, { headers: E2E_HEADER });

	expect(response.ok(), `the nonce route answered ${response.status()}`).toBeTruthy();

	return response.json();
}

/** User meta as stored, arrays and absence included (`null` = no row). */
export async function metaOf(api: APIRequestContext, email: string, keys: string[], prefix = ''): Promise<Record<string, unknown>> {
	const response = await api.get(
		`${prefix}${E2E_NS}/refusals/meta?email=${encodeURIComponent(email)}&keys=${encodeURIComponent(keys.join(','))}`,
		{ headers: E2E_HEADER }
	);

	expect(response.ok(), `the meta route answered ${response.status()}`).toBeTruthy();

	const answer = await response.json();

	return answer.exists ? (answer.meta as Record<string, unknown>) : { exists: false };
}

/**
 * A browser context signed in as somebody, through the emergency door.
 *
 * `wp-login.php?diluxone-users-admin=1` is WordPress's own form whatever the
 * plugin is set to, so the way in does not depend on the settings a refusal
 * test is about to change. What is asserted afterwards is that the site sees
 * a session, not that a particular screen opened.
 */
export async function signedInContext(browser: Browser, baseURL: string, login: string, password: string, siteUrl?: string): Promise<BrowserContext> {
	// An empty state said out loud: inside a spec that runs as the
	// administrator, `undefined` is filled in with the spec's own state.
	const context = await browser.newContext({ baseURL, storageState: NOBODY });
	const page = await context.newPage();
	const at = (siteUrl ?? `${baseURL.replace(/\/$/, '')}/`).replace(/\/$/, '');

	await page.goto(`${at}/wp-login.php?diluxone-users-admin=1`);
	await fillCredentials(page, login, password);
	await Promise.all([page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'), { waitUntil: 'domcontentloaded' }), page.locator('#wp-submit').click()]);

	const profile = await context.request.get(`${at}/wp-admin/profile.php`, { maxRedirects: 0, failOnStatusCode: false });

	expect(profile.status(), `${login} has a session`).toBe(200);
	await page.close();

	return context;
}

/** The address a signed-in context's profile shows: whose session it is. */
export async function emailOf(api: APIRequestContext, siteUrl = ''): Promise<string> {
	const html = await (await api.get(`${siteUrl}/wp-admin/profile.php`)).text();
	const found = html.match(/name="email"[^>]*value="([^"]*)"/);

	expect(found, 'the profile shows an address').not.toBeNull();

	return (found as RegExpMatchArray)[1];
}

/**
 * The fields of a form on a screen, as the browser would send them.
 *
 * Read from the real page, so a refusal test posts exactly what the form
 * posts — a field renamed tomorrow is renamed here too — with one control
 * changed the way a person would change it.
 *
 * @param selector The form.
 * @param change   name → value: a radio picks that value, a single checkbox is
 *                 ticked for '1' and unticked for '', a box of a list
 *                 (`name[]`) is toggled by its value, anything else is typed.
 */
export async function formFields(
	page: import('@playwright/test').Page,
	selector: string,
	change: Record<string, string> = {}
): Promise<Record<string, string[]>> {
	return page.locator(selector).first().evaluate((form: HTMLFormElement, change: Record<string, string>) => {
		const elements = [...form.elements] as Array<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>;

		for (const [name, value] of Object.entries(change)) {
			const named = elements.filter((element) => element.name === name);

			if (named.length === 0) {
				throw new Error(`The form has no field ${name}.`);
			}

			const first = named[0] as HTMLInputElement;

			if (first.type === 'radio') {
				for (const radio of named as HTMLInputElement[]) {
					radio.checked = radio.value === value;
				}
			} else if (first.type === 'checkbox' && name.endsWith('[]')) {
				const box = (named as HTMLInputElement[]).find((one) => one.value === value);

				if (!box) {
					throw new Error(`The list ${name} has no box ${value}.`);
				}

				box.checked = !box.checked;
			} else if (first.type === 'checkbox') {
				first.checked = value === '1';
			} else {
				first.value = value;
			}
		}

		const out: Record<string, string[]> = {};

		for (const [name, value] of new FormData(form).entries()) {
			(out[name] ??= []).push(String(value));
		}

		return out;
	}, change);
}

/**
 * WP-CLI on the single-site DEV environment (`npx wp-env run cli`), for the
 * few things a refusal needs that no browser and no REST route makes: a data
 * request that belongs to somebody else, with its key.
 */
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

/* ── A table of refusals ───────────────────────────────────────────── */

/** The ways a request is refused, and the one where it is somebody else's thing. */
export type Kind = 'no nonce' | 'forged nonce' | 'without the right' | 'signed out' | 'as somebody else';

export const KINDS: Kind[] = ['no nonce', 'forged nonce', 'without the right', 'signed out', 'as somebody else'];

export type Multipart = Record<string, string | { name: string; mimeType: string; buffer: Buffer }>;

/**
 * One door that writes, and how each kind of refusal is answered there.
 *
 * `W` is the spec's world: who is signed in, the side doors, the people a
 * forged request would act on.
 */
export interface Row<W> {
	/** What it is, as the test title says it. */
	id: string;
	method: 'GET' | 'POST';
	/** The address, relative to the base URL; a GET carries its query here. */
	url: (w: W) => string;
	fields?: (w: W) => Record<string, string | string[]>;
	multipart?: (w: W) => Multipart;
	/** Where the nonce travels and what it is for. */
	nonce?: { field: string; action: string | ((w: W) => string) };
	/** Who may send it: (a) and (b) are sent as this person. */
	actor: string;
	/** Who sends (c): somebody without the capability, with a nonce valid for them. */
	lacking?: string;
	/** (e): another signed-in person, with their own valid nonce, at something that is not theirs. */
	other?: { who?: string; fields?: (w: W) => Record<string, string | string[]>; url?: (w: W) => string };
	expect: Partial<Record<Kind, Outcome>>;
	/** Whatever the request would have written, read back before and after. */
	probe: (w: W) => Promise<unknown>;
	/** Preconditions; the spec hands its own fixtures in. */
	prepare?: (fixtures: any, w: W) => Promise<void>;
	/** Something a refusal must never hand back, whatever its status. */
	leaks?: (answer: Answer) => void;
}

/** The tests a row makes: one per kind it has an answer for. */
export function kindsOf<W>(row: Row<W>): Kind[] {
	return KINDS.filter(
		(kind) =>
			row.expect[kind] !== undefined && !(kind === 'without the right' && !row.lacking) && !(kind === 'as somebody else' && !row.other)
	);
}

/**
 * Sends a row as one kind of refusal.
 *
 * @param api   Who is who: the request context of each name a row uses, and `guest`.
 */
export async function fire<W>(w: W, row: Row<W>, kind: Kind, api: (who: string) => APIRequestContext): Promise<Answer> {
	let who = row.actor;
	let nonce: string | null = null;
	let fields = row.fields?.(w);
	let url = row.url(w);
	const action = row.nonce ? (typeof row.nonce.action === 'string' ? row.nonce.action : row.nonce.action(w)) : '';

	if (kind === 'forged nonce') {
		nonce = FORGED;
	} else if (kind === 'without the right') {
		who = row.lacking as string;
		nonce = (await nonceFor(api(who), action)).nonce;
	} else if (kind === 'signed out') {
		who = 'guest';
		// What a stranger's page would carry: a nonce made for nobody.
		nonce = (await nonceFor(api('guest'), action)).nonce;
	} else if (kind === 'as somebody else') {
		who = row.other?.who ?? 'subscriber';
		nonce = (await nonceFor(api(who), action)).nonce;
		fields = row.other?.fields?.(w) ?? fields;
		url = row.other?.url?.(w) ?? url;
	}

	const signed = nonce !== null && row.nonce !== undefined;

	if (row.multipart) {
		const parts = row.multipart(w);

		return send(api(who), 'POST', url, undefined, signed ? { ...parts, [row.nonce!.field]: nonce as string } : parts);
	}

	if (signed && row.method === 'GET') {
		url += `${url.includes('?') ? '&' : '?'}${row.nonce!.field}=${nonce}`;
	} else if (signed) {
		fields = { ...(fields ?? {}), [row.nonce!.field]: nonce as string };
	}

	return send(api(who), row.method, url, row.method === 'POST' ? fields ?? {} : undefined);
}
