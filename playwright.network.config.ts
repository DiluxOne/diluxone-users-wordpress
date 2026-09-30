import { defineConfig, devices } from '@playwright/test';

/**
 * The network suite: the same plugin, network-activated, on the wp-env TESTS
 * site converted into a subdirectory multisite (`make env-multisite`).
 *
 * A config of its own, and not a project of playwright.config.ts, for two
 * reasons. The default `npx playwright test` is what the shared CI runs against
 * a single dev site with the plugin activated there, and it has to stay
 * exactly that. And the two suites drive two different WordPress installs:
 * sharing a config would mean sharing a baseURL, a setup and a teardown that
 * are each right for one of them only.
 *
 * `make test-e2e-network` converts the site and runs this. The setup makes
 * two sites, /alpha/ and /beta/, seeds the plugin's pages on each of them and
 * writes down the network settings it touches; the teardown deletes both sites
 * and puts the network settings back.
 *
 * Override WP_NETWORK_URL to point it at another network (subdirectory, with
 * the e2e mu-plugin and WP-CLI reachable through `npx wp-env run tests-cli`).
 */

export const NETWORK_URL = (process.env.WP_NETWORK_URL ?? 'http://localhost:8893').replace(/\/$/, '');

/** A super admin session, kept by the setup, valid on every site of the network. */
export const NETWORK_ADMIN_STATE = 'build/e2e-network-admin.json';

/** Whether the pictures are being compared this run: see playwright.config.ts. */
const PICTURES = process.env.DU_SNAPSHOTS === '1';

export default defineConfig({
	testDir: './tests/e2e/network',
	timeout: 90_000,
	expect: {
		timeout: 10_000,
		// The same pinning as the single-site pictures, for the same reasons.
		toHaveScreenshot: { animations: 'disabled', caret: 'hide', scale: 'css', maxDiffPixelRatio: 0.002 },
	},
	// One network, one options table per site, one debug.log for all of them.
	fullyParallel: false,
	workers: 1,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [['github'], ['list']] : [['list']],
	// Beside the single-site suite's folder, not inside it: Playwright empties
	// its output folder when a run starts, and the two suites can run at once.
	outputDir: 'build/e2e-network-results',
	// Beside the single-site pictures, named apart by their `network-` prefix.
	snapshotPathTemplate: 'tests/e2e/snapshots/{arg}-{platform}{ext}',
	use: {
		baseURL: NETWORK_URL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
	},
	projects: [
		{
			name: 'network-setup',
			testMatch: /network\.setup\.ts/,
			teardown: 'network-teardown',
		},
		{
			name: 'network-teardown',
			testMatch: /network\.teardown\.ts/,
		},
		{
			name: 'network',
			testMatch: /\.spec\.ts$/,
			testIgnore: /network-snapshots\.spec\.ts/,
			use: { ...devices['Desktop Chrome'] },
			dependencies: ['network-setup'],
		},
		...(PICTURES
			? [
					{
						name: 'network-visual',
						testMatch: /network-snapshots\.spec\.ts/,
						use: {
							...devices['Desktop Chrome'],
							viewport: { width: 1280, height: 900 },
							deviceScaleFactor: 1,
							reducedMotion: 'reduce' as const,
						},
						dependencies: ['network-setup'],
					},
				]
			: []),
	],
});
