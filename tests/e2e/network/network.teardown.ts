import { test as teardown } from '@playwright/test';
import { existsSync, readFileSync, rmSync } from 'node:fs';
import { Site } from '../support/api';
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
	await (await Site.open(`${NETWORK_URL}/`)).deleteE2EUsers();

	const paths = wp(['site', 'list', '--field=path']).split('\n');

	for (const slug of SUBSITES) {
		if (paths.includes(`/${slug}/`)) {
			wp(['site', 'delete', `--slug=${slug}`, '--yes']);
		}
	}

	// A spec that makes a site of its own deletes it; one that failed halfway
	// may not have got that far.
	for (const path of paths.filter((one) => /^\/e2e-new-/.test(one))) {
		wp(['site', 'delete', `--slug=${path.replace(/\//g, '')}`, '--yes']);
	}

	if (existsSync(NETWORK_BASELINE_FILE)) {
		const previous = JSON.parse(readFileSync(NETWORK_BASELINE_FILE, 'utf8')) as {
			registration: string;
			wpDebug: string;
		};

		wp(['site', 'option', 'update', 'registration', previous.registration]);
		wp(['config', 'set', 'WP_DEBUG', previous.wpDebug === 'true' ? 'true' : 'false', '--raw', '--type=constant']);
		rmSync(NETWORK_BASELINE_FILE);
	}

	if (existsSync(NETWORK_PAGES_FILE)) {
		rmSync(NETWORK_PAGES_FILE);
	}
});
