import { execFileSync } from 'node:child_process';
import { APIRequestContext, Browser, BrowserContext, Locator, Page, expect } from '@playwright/test';
import { E2E_HEADER, E2E_NS, Site, freshEmail, sitePrefix } from './api';
import { expectSignedIn } from './fixtures';
import { signInWithPassword } from './ui';

/**
 * What the specs of the screens an administrator works on share: Security,
 * Social login, Status, Reports and WordPress's own screens
 * (`admin-security-*`, `admin-social-*`, `admin-status-*`, `admin-reports-*`,
 * `wp-screens-*`, on a single site and on a network).
 *
 * Like the rest of `support/`, everything here points at markup and never at
 * a sentence: the single site runs in Spanish and the network in English.
 */

/* ── The summary tables ────────────────────────────────────────────── */

/**
 * One row of a summary table, by the tab its link points at.
 *
 * Several rows can link to the same tab, so `index` says which one, in the
 * order the table draws them.
 */
export function summaryRow(page: Page, screen: string, tab: string, index = 0): Locator {
	return page
		.locator('table.diluxone-users-summary')
		.first()
		.locator('tbody tr')
		.filter({ has: page.locator(`td.diluxone-users-summary__change a[href*="page=${screen}&tab=${tab}"]`) })
		.nth(index);
}

/** A row of the first summary table on the screen, by its place in it. */
export function summaryRowAt(page: Page, index: number): Locator {
	return page.locator('table.diluxone-users-summary').first().locator('tbody > tr').nth(index);
}

/** The state pill of a row, or of anything that carries one. */
export function statePill(scope: Locator): Locator {
	return scope.locator('.diluxone-users-state').first();
}

/** The state a pill is in: `active`, `off`, `pending`, `unknown`. */
export async function stateIn(scope: Locator): Promise<string> {
	const classes = (await statePill(scope).getAttribute('class')) ?? '';

	return classes.match(/diluxone-users-state--([a-z]+)/)?.[1] ?? '';
}

/** The column beside a settings tab: the notes and links a screen says about itself. */
export function rail(page: Page): Locator {
	return page.locator('.diluxone-users-studio__aside').first();
}

/** The "not now" lines, which say a control does not apply right now and why. */
export function notNow(page: Page): Locator {
	return page.locator('.diluxone-users-not-now');
}

/* ── People in browsers of their own ───────────────────────────────── */

export interface Person {
	email: string;
	password: string;
	id: number;
	context: BrowserContext;
	page: Page;
}

/**
 * Somebody new, signed in with a password in a browser of their own.
 *
 * @param headers Sent with every request of that browser — a proxy's header, say.
 */
export async function personSignedIn(
	browser: Browser,
	baseURL: string,
	site: Site,
	loginUrl: string,
	prefix: string,
	options: { role?: string; password?: string; headers?: Record<string, string>; meta?: Record<string, unknown> } = {}
): Promise<Person> {
	const email = freshEmail(prefix);
	const password = options.password ?? 'e2e-Admin-Ops-1!';

	expect(email.length, 'a username WordPress accepts').toBeLessThanOrEqual(60);

	const made = await site.makeUser({ email, password, role: options.role, meta: options.meta });
	const context = await browser.newContext({
		baseURL,
		storageState: { cookies: [], origins: [] },
		extraHTTPHeaders: options.headers,
	});
	const page = await context.newPage();

	await page.goto(loginUrl);
	await signInWithPassword(page, email, password);
	await expectSignedIn(page, email);

	return { email, password, id: made.id, context, page };
}

/* ── The side door's routes of this group of specs ─────────────────── */

/**
 * The routes the mu-plugin adds for these specs (its `admin-reports:`
 * block): rows of the activity log a spec writes and takes away again, a copy
 * of the log kept while a spec empties it, and every account's sessions kept
 * while a spec closes them all.
 */
export class Ops {
	private readonly prefix: string;

	constructor(
		private readonly api: APIRequestContext,
		baseURL = ''
	) {
		this.prefix = baseURL ? sitePrefix(baseURL) : '';
	}

	private async call(method: 'post' | 'delete', path: string, data?: unknown): Promise<any> {
		const response = await this.api[method](`${this.prefix}${E2E_NS}/h3${path}`, {
			headers: E2E_HEADER,
			...(data === undefined ? {} : { data }),
		});

		expect(response.ok(), `${method.toUpperCase()} ${path} answered ${response.status()}: ${await response.text()}`).toBeTruthy();

		return response.json();
	}

	/** Rows of the spec's own, carrying `tag`, on this site's activity log. */
	addLogRows(rows: { tag: string; count?: number; event?: string; email?: string; ip?: string; days?: number }): Promise<unknown> {
		return this.call('post', '/log-rows', rows);
	}

	/** The spec's own rows, by tag, gone. Nothing else is touched. */
	deleteLogRows(tag: string): Promise<unknown> {
		return this.call('delete', `/log-rows?tag=${encodeURIComponent(tag)}`);
	}

	/** A copy of every row of the log, to be put back by restoreLog(). */
	async keepLog(): Promise<number> {
		return (await this.call('post', '/log-keep')).kept as number;
	}

	restoreLog(): Promise<unknown> {
		return this.call('delete', '/log-keep');
	}

	/** A copy of every account's sessions, to be put back by restoreSessions(). */
	keepSessions(): Promise<unknown> {
		return this.call('post', '/sessions-keep');
	}

	restoreSessions(): Promise<unknown> {
		return this.call('delete', '/sessions-keep');
	}
}

/**
 * A tag nobody else's rows carry.
 *
 * Kept short: it ends up inside e-mail addresses, and an address is the
 * username here — WordPress refuses one over sixty characters, and the side
 * door's /user route then writes the meta it was given onto account 1.
 */
export function freshTag(prefix: string): string {
	return `${prefix}${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`;
}

/** The address of a cookie's expiry, in days from now. */
export function daysUntil(expires: number): number {
	return (expires * 1000 - Date.now()) / 86_400_000;
}

/**
 * `savePanel()`, with the reason the save must not be refused said in the
 * failure: for a save a product bug refuses today (`bug: …`).
 */
export async function saveSaying(page: Page, why: string): Promise<void> {
	await Promise.all([
		page.waitForResponse((response) => response.request().method() === 'POST' && response.request().resourceType() === 'document'),
		page.locator('[data-diluxone-users-save] .du-save__button').first().click(),
	]);
	await page.waitForLoadState('domcontentloaded');

	await expect(page.locator('.notice-error'), why).toHaveCount(0);
	await expect(page.locator('.notice-success'), why).toBeVisible();
}

/**
 * WP-CLI on the single-site suite's dev site (`npx wp-env run cli`), for what
 * nothing a person does can make: a draft page, the lockout command.
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

/* ── On a network ──────────────────────────────────────────────────── */

/** An address of Network Admin: `networkAdmin('admin.php?page=…')`. */
export function networkAdmin(networkUrl: string, rest: string): string {
	return `${networkUrl.replace(/\/$/, '')}/wp-admin/network/${rest.replace(/^\//, '')}`;
}

/** One of the plugin's screens in Network Admin, on one of its tabs. */
export function networkScreen(networkUrl: string, screen: string, tab?: string, query: Record<string, string> = {}): string {
	const extra = Object.entries(query)
		.map(([key, value]) => `&${encodeURIComponent(key)}=${encodeURIComponent(value)}`)
		.join('');

	return networkAdmin(networkUrl, `admin.php?page=${screen}${tab ? `&tab=${tab}` : ''}${extra}`);
}

/** One of the plugin's screens on one site of the network. */
export function siteScreen(siteUrl: string, screen: string, tab?: string, query: Record<string, string> = {}): string {
	return `${siteUrl.replace(/\/$/, '')}${adminUrlOf(screen, tab, query)}`;
}

function adminUrlOf(screen: string, tab?: string, query: Record<string, string> = {}): string {
	const extra = Object.entries(query)
		.map(([key, value]) => `&${encodeURIComponent(key)}=${encodeURIComponent(value)}`)
		.join('');

	return `/wp-admin/admin.php?page=${screen}${tab ? `&tab=${tab}` : ''}${extra}`;
}

/** The expiry WordPress wrote into a session cookie's value, in days from now. */
export async function sessionValueDays(context: BrowserContext): Promise<number> {
	const cookie = (await context.cookies()).find((one) => one.name.startsWith('wordpress_logged_in_'));

	expect(cookie, 'a session cookie').toBeTruthy();

	return (Number(decodeURIComponent(cookie!.value).split('|')[1]) - Date.now() / 1000) / 86_400;
}

/**
 * Presses Close on the social live test's result window.
 *
 * The button closes its own window, so the click can be told the page is
 * gone before it hears that it landed: what is waited for is the close
 * event, by the caller, and a click that "failed" because the window
 * closed is the click working.
 */
export async function closeTheTest(popup: Page): Promise<void> {
	await popup
		.locator('[data-diluxone-users-sso-test-close]')
		.click()
		.catch((error: Error) => {
			if (!/closed/i.test(error.message)) {
				throw error;
			}
		});
}
