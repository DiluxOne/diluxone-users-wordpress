import { test as base, expect, Page, request as playwrightRequest } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { OptionBag, SeedPages, Site } from '../support/api';
import { wp } from '../support/cli';
import { NETWORK_URL } from '../../../playwright.network.config';

/**
 * What every network spec gets handed: the two sites, each with its own side
 * door, its own pages and its own settings that are put back when the test
 * ends — plus the network's own settings, which belong to no site.
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
	slug: string;
	/** `http://…/alpha/` */
	url: string;
	/** The REST side door of THIS site: its options, its mailbox, its members. */
	site: Site;
	pages: SeedPages['pages'];
	/** Settings of THIS site, put back when the test ends. */
	set(values: OptionBag): Promise<void>;
	/** An address on this site: `admin('users.php')`, `path('e2e-login/')`. */
	path(rest: string): string;
	admin(rest: string): string;
}

/** A setting of the network itself — `registration`, and nothing else so far. */
export interface NetworkOptions {
	set(key: string, value: string): Promise<void>;
}

async function handle(slug: Subsite, remember: (site: Site, bag: OptionBag) => void): Promise<SiteHandle> {
	const url = subsiteUrl(slug);
	const site = await Site.open(url);
	const all = JSON.parse(readFileSync(NETWORK_PAGES_FILE, 'utf8')) as Record<string, SeedPages['pages']>;

	return {
		slug,
		url,
		site,
		pages: all[slug],
		async set(values) {
			remember(site, await site.setOptions(values));
		},
		path: (rest) => `${url}${rest.replace(/^\//, '')}`,
		admin: (rest) => `${url}wp-admin/${rest.replace(/^\//, '')}`,
	};
}

export const test = base.extend<{
	alpha: SiteHandle;
	beta: SiteHandle;
	network: NetworkOptions;
	root: Site;
	guest: Page;
	freshCounters: void;
}>({
	/**
	 * Per site, like everything the plugin keeps: two maps, not one, so that a
	 * value restored on /alpha/ can never land on /beta/.
	 */
	alpha: async ({}, use) => {
		const original = new Map<Site, OptionBag>();
		const h = await handle('alpha', (site, bag) => keepFirst(original, site, bag));

		await use(h);
		await restore(original);
	},

	beta: async ({}, use) => {
		const original = new Map<Site, OptionBag>();
		const h = await handle('beta', (site, bag) => keepFirst(original, site, bag));

		await use(h);
		await restore(original);
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

function keepFirst(original: Map<Site, OptionBag>, site: Site, bag: OptionBag): void {
	const kept = original.get(site) ?? {};

	for (const [key, was] of Object.entries(bag)) {
		if (!(key in kept)) {
			kept[key] = was;
		}
	}

	original.set(site, kept);
}

async function restore(original: Map<Site, OptionBag>): Promise<void> {
	for (const [site, bag] of original) {
		if (Object.keys(bag).length > 0) {
			await site.setOptions(bag, { forgetTransients: true });
		}
	}
}

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

/** A REST context with no session at all, for a question a stranger would ask. */
export async function anonymous(baseURL: string) {
	return playwrightRequest.newContext({ baseURL });
}
