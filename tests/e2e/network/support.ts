import { test as base, expect, Page, request as playwrightRequest } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { OptionBag, SeedPages, Site } from '../support/api';
import { wp } from '../support/cli';
import { NETWORK_URL } from '../../../playwright.network.config';

/**
 * What every network spec gets handed: the main site — the hub, where the
 * network's people sign in and keep their account — and two more sites, each
 * with its own side door and its own pages, plus WordPress's own network
 * settings.
 *
 * A setting written through any of them lands where its scope says: a network
 * setting in the network's options, a hub setting on the main site, a site
 * setting on that site. So what a test changed is put back in one list, newest
 * first, whichever site it was written through: two sites writing the same
 * network setting would otherwise each put back what they found, and the last
 * one to do it would win.
 */

export const SUBSITES = ['alpha', 'beta'] as const;
export type Subsite = (typeof SUBSITES)[number];

export const NETWORK_BASELINE_FILE = 'build/e2e-network-baseline.json';
export const NETWORK_PAGES_FILE = 'build/e2e-network-pages.json';

/** A site's address, with the trailing slash WordPress gives a home. */
export function subsiteUrl(slug: string): string {
	return `${NETWORK_URL}/${slug}/`;
}

/** One site of the network, as a spec wants it. */
export interface SiteHandle {
	/** `alpha`, or '' for the main site. */
	slug: string;
	/** `http://…/alpha/` */
	url: string;
	/** The REST side door of THIS site: its options, its mailbox, its members. */
	site: Site;
	pages: SeedPages['pages'];
	/** Settings, written through this site and put back when the test ends. */
	set(values: OptionBag): Promise<void>;
	/** Settings a screen is about to save: what they are now is put back when the test ends. */
	keep(keys: string[]): Promise<void>;
	/** An address on this site: `admin('users.php')`, `path('e2e-login/')`. */
	path(rest: string): string;
	admin(rest: string): string;
}

/** A setting of the network itself — `registration`, and nothing else so far. */
export interface NetworkOptions {
	set(key: string, value: string): Promise<void>;
}

/** What a test changed, in the order it changed it. */
type Changes = Array<{ site: Site; bag: OptionBag }>;

async function handle(slug: Subsite | '', changes: Changes): Promise<SiteHandle> {
	const url = '' === slug ? `${NETWORK_URL}/` : subsiteUrl(slug);
	const site = await Site.open(url);
	const all = JSON.parse(readFileSync(NETWORK_PAGES_FILE, 'utf8')) as Record<string, SeedPages['pages']>;

	return {
		slug,
		url,
		site,
		pages: all['' === slug ? 'root' : slug],
		async set(values) {
			changes.push({ site, bag: await site.setOptions(values) });
		},
		async keep(keys) {
			changes.push({ site, bag: await site.getOptions(keys) });
		},
		path: (rest) => `${url}${rest.replace(/^\//, '')}`,
		admin: (rest) => `${url}wp-admin/${rest.replace(/^\//, '')}`,
	};
}

export const test = base.extend<{
	changes: Changes;
	hub: SiteHandle;
	alpha: SiteHandle;
	beta: SiteHandle;
	network: NetworkOptions;
	root: Site;
	guest: Page;
	freshCounters: void;
}>({
	/** Everything the test wrote, put back newest first. */
	changes: async ({}, use) => {
		const changes: Changes = [];

		await use(changes);

		for (const { site, bag } of changes.reverse()) {
			if (Object.keys(bag).length > 0) {
				await site.setOptions(bag, { forgetTransients: true });
			}
		}
	},

	/** The main site: the hub, whose pages are where the network signs in. */
	hub: async ({ changes }, use) => {
		await use(await handle('', changes));
	},

	alpha: async ({ changes }, use) => {
		await use(await handle('alpha', changes));
	},

	beta: async ({ changes }, use) => {
		await use(await handle('beta', changes));
	},

	/** The main site's side door: the one that sees every account of the network. */
	root: async ({}, use) => {
		await use(await Site.open(`${NETWORK_URL}/`));
	},

	network: async ({}, use) => {
		const original = new Map<string, string>();

		await use({
			async set(key, value) {
				if (!original.has(key)) {
					original.set(key, wp(['site', 'option', 'get', key]));
				}

				wp(['site', 'option', 'update', key, value]);
			},
		});

		for (const [key, value] of original) {
			wp(['site', 'option', 'update', key, value]);
		}
	},

	/** The throttles are per site; both start at zero. */
	freshCounters: [
		async ({}, use) => {
			for (const slug of SUBSITES) {
				await (await Site.open(subsiteUrl(slug))).setOptions({}, { forgetTransients: true });
			}

			await use();
		},
		{ auto: true },
	],

	/** A second browser with nobody signed in, for the specs that have an admin session. */
	guest: async ({ browser }, use) => {
		const context = await browser.newContext({ storageState: undefined });
		const page = await context.newPage();

		await use(page);
		await context.close();
	},
});

export { expect };

/**
 * Who this browser is signed in as, asked of one site of the network.
 *
 * The single-site helper asks `/wp-admin/profile.php` at the root. On a
 * network that answers a different question: somebody who is not a member of
 * the main site is sent on to their own dashboard, and somebody with no
 * session at all to wp-login.php. So this asks the site in question for its
 * profile screen and reads where it ended up: wp-login.php is nobody; any
 * other screen is somebody, and the profile screen says who.
 *
 * @returns The e-mail on the profile, '' when signed in but sent elsewhere,
 *          null when there is no session.
 */
export async function whoOn(page: Page, siteUrl: string): Promise<string | null> {
	const response = await page.request.get(`${siteUrl}wp-admin/profile.php`);
	const landed = new URL(response.url());

	if (landed.pathname.endsWith('/wp-login.php') || landed.searchParams.has('diluxone_users_2fa')) {
		return null;
	}

	const found = (await response.text()).match(/name="email"[^>]*value="([^"]*)"/);

	return found ? found[1] : '';
}

/**
 * Whether this browser's session opens a site's dashboard.
 *
 * `/wp-admin/` itself and not the profile: the question in the two-step spec
 * is whether an administrator's cookie is an administrator's session on the
 * site that asks for a second step, and the dashboard is what that session is
 * for. Anything but a 200 on a screen under /wp-admin/ is a no.
 */
export async function opensDashboard(page: Page, siteUrl: string): Promise<boolean> {
	const response = await page.request.get(`${siteUrl}wp-admin/`, { failOnStatusCode: false });
	const landed = new URL(response.url());

	return (
		response.status() === 200 &&
		landed.pathname.startsWith(new URL(siteUrl).pathname + 'wp-admin') &&
		!landed.pathname.endsWith('/wp-login.php')
	);
}

/**
 * A page of one site that draws one of the plugin's shortcodes, made once.
 *
 * On a network the account area is the main site's: its sections are routed
 * there and nowhere else. The pieces of it still work on a page of any site —
 * the photo, the linked social accounts — and a spec that is about which site
 * something happens on puts the piece on a page of that site.
 *
 * @returns The page's address.
 */
export function pageWith(one: SiteHandle, shortcode: string): string {
	const slug = `e2e-${shortcode.replace(/[^a-z]+/g, '-').replace(/^-|-$/g, '')}`;
	const found = wp(['post', 'list', '--post_type=page', `--name=${slug}`, '--field=url'], one.url);

	if ('' !== found) {
		return found.split('\n')[0];
	}

	const id = wp(
		['post', 'create', '--post_type=page', '--post_status=publish', `--post_name=${slug}`, `--post_title=${slug}`, `--post_content=[${shortcode}]`, '--porcelain'],
		one.url
	);

	return wp(['post', 'list', '--post_type=page', `--post__in=${id}`, '--field=url'], one.url);
}

/** A REST context with no session at all, for a question a stranger would ask. */
export async function anonymous(baseURL: string) {
	return playwrightRequest.newContext({ baseURL });
}
