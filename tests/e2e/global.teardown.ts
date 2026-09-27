import { test as teardown } from '@playwright/test';
import { existsSync, readFileSync, rmSync } from 'node:fs';
import { OptionBag, Site } from './support/api';
import { BASELINE_FILE } from './support/fixtures';

/**
 * Puts the settings back and takes the accounts away.
 *
 * It runs whatever happened above: Playwright runs a project's teardown even
 * when the project failed, which is exactly when leaving a site in "only a
 * link, no passwords" would be most annoying.
 */

teardown('put the site back the way it was', async ({ baseURL }) => {
	const site = await Site.open(baseURL!);

	if (existsSync(BASELINE_FILE)) {
		const previous = JSON.parse(readFileSync(BASELINE_FILE, 'utf8')) as OptionBag;

		await site.setOptions(previous, { flush: true, forgetTransients: true });
		rmSync(BASELINE_FILE);
	}

	// Every account the suite made carries the e2e domain; nothing else does.
	await site.deleteE2EUsers();
	await site.forgetMenu();
	await site.clearMail();
});
