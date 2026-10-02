import { test as teardown } from '@playwright/test';
import { existsSync, readFileSync, rmSync } from 'node:fs';
import { OptionBag, Site } from '../support/api';
import { wp } from '../support/cli';
import { NETWORK_URL } from '../../../playwright.network.config';
import { NETWORK_BASELINE_FILE, NETWORK_PAGES_FILE, SUBSITES } from './support';

/**
 * Takes the sites away and puts the network back.
 *
 * Runs whatever happened above, like the single-site teardown. The accounts
 * go first and through the main site: on a network an account belongs to no
 * site in particular, and deleting a site leaves its members' accounts behind.
 */

teardown('delete the sites, the accounts, and put the network back', async () => {
	// Every step is tried, and the network's settings are put back whatever
	// failed before them: a teardown that stops at its first error leaves the
	// next run on a network nobody set up.
	const failures: string[] = [];
	const attempt = async (what: string, step: () => unknown): Promise<void> => {
		try {
			await step();
		} catch (error) {
			failures.push(`${what}: ${(error as Error).message}`);
		}
	};

	try {
		await attempt('the accounts', async () => (await Site.open(`${NETWORK_URL}/`)).deleteE2EUsers());

		// And proof that none is left: an account the suite made carries a mark
		// from the moment it is made, whatever its address became since — a
		// closed account kept for its content is `deleted-<id>` by now.
		await attempt('nothing of the suite left', () => {
			const left = wp(['user', 'list', '--network', '--meta_key=diluxone_e2e_made', '--field=user_login']).trim();

			if (left !== '') {
				throw new Error(`accounts the suite made are still there: ${left.split('\n').join(', ')}`);
			}
		});

		const sites = JSON.parse(wp(['site', 'list', '--fields=blog_id,path', '--format=json'])) as { blog_id: string | number; path: string }[];

		// The two sites of the suite, and any a spec made for itself
		// (e2e-new-, e2e-born-, e2e-mapped-) and did not get to delete. By id:
		// a site given a domain of its own is no longer found by its slug.
		const ours = sites.filter((one) => SUBSITES.some((slug) => one.path === `/${slug}/`) || /^\/e2e-/.test(one.path));

		for (const one of ours) {
			await attempt(`the site ${one.path}`, () => wp(['site', 'delete', String(one.blog_id), '--yes']));
		}
	} finally {
		if (existsSync(NETWORK_BASELINE_FILE)) {
			const previous = JSON.parse(readFileSync(NETWORK_BASELINE_FILE, 'utf8')) as {
				registration: string;
				wpDebug: string;
				options?: OptionBag;
			};

			// The network's settings and the hub's, as they were before the run.
			if (previous.options && Object.keys(previous.options).length > 0) {
				await attempt('the settings', async () => (await Site.open(`${NETWORK_URL}/`)).setOptions(previous.options as OptionBag, { forgetTransients: true }));
			}

			await attempt('the registration setting', () => wp(['site', 'option', 'update', 'registration', previous.registration]));
			await attempt('WP_DEBUG', () => wp(['config', 'set', 'WP_DEBUG', previous.wpDebug === 'true' ? 'true' : 'false', '--raw', '--type=constant']));
			rmSync(NETWORK_BASELINE_FILE);
		}

		if (existsSync(NETWORK_PAGES_FILE)) {
			rmSync(NETWORK_PAGES_FILE);
		}
	}

	if (failures.length > 0) {
		throw new Error(`The teardown could not finish:\n${failures.join('\n')}`);
	}
});
