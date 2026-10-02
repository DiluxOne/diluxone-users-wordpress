import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end tests against the wp-env DEV site (port 8892).
 *
 * The dev site mounts the repo root, so what runs here is the working tree,
 * not the built dist — and tests/e2e/mu-plugin/diluxone-e2e.php is mapped into
 * mu-plugins from .wp-env.json, which is what lets a test read the site's mail
 * and answer as a social network.
 *
 * Almost every spec is somebody who is NOT signed in: that is what signing in
 * means. So there is no shared storage state; the specs that need the
 * dashboard say so themselves with `test.use({ storageState: ADMIN_STATE })`.
 *
 * Override WP_BASE_URL / WP_USER / WP_PASS to point the suite elsewhere.
 */

export const ADMIN_STATE = 'build/e2e-admin.json';

/**
 * Whether the pictures are being compared this run.
 *
 * A baseline image is a picture of ONE machine's font rendering, its
 * sub-pixel smoothing and its scrollbars. Committing those and asking a CI
 * runner to match them is asking for a job that is red for reasons nobody can
 * act on, and a gate nobody can act on is a gate that gets switched off. So
 * the picture suite is a project that only exists when it is asked for —
 * `make test-visual`, on the machine whose pictures these are — while the
 * measurements in `admin-layout.spec.ts` mean the same thing everywhere and
 * run with everything else.
 */
const PICTURES = process.env.DU_SNAPSHOTS === '1';

/**
 * Whether this run is taking the pictures the wordpress.org listing shows.
 *
 * A project of its own for the same reason as the one above — a fixed window
 * and no motion — and opt-in for a different one: it WRITES into
 * `.wordpress-org/`, which is the listing itself. Nothing that writes the
 * shop window should run as a side effect of `make test-e2e`.
 */
const LISTING = process.env.DU_LISTING === '1';

export default defineConfig({
	testDir: './tests/e2e/specs',
	timeout: 60_000,
	expect: {
		timeout: 10_000,
		toHaveScreenshot: {
			// Nothing moves, nothing blinks, and the text cursor is not in the
			// picture. Without these three a screen with a focused field is a
			// different picture every other second.
			animations: 'disabled',
			caret: 'hide',
			// In CSS pixels, so a machine with a retina display and one
			// without take the same picture.
			scale: 'css',
			// Antialiasing on a curve is a handful of pixels either way and is
			// not a regression. Two in a thousand is well under anything a
			// person would see, and well over what a font renderer wobbles by.
			maxDiffPixelRatio: 0.002,
		},
	},
	// The suite drives one WordPress install and changes its settings, so two
	// tests at once would be two tests fighting over the same options table.
	fullyParallel: false,
	workers: 1,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [['github'], ['list']] : [['list']],
	outputDir: 'build/e2e-results',
	// Beside the specs, under the name of the machine that took them: a
	// baseline belongs to a platform, and a filename that says so is how
	// somebody knows why theirs does not match.
	snapshotPathTemplate: 'tests/e2e/snapshots/{arg}-{platform}{ext}',
	use: {
		baseURL: process.env.WP_BASE_URL ?? 'http://localhost:8892',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
	},
	projects: [
		{
			name: 'setup',
			testDir: './tests/e2e',
			testMatch: /global\.setup\.ts/,
			teardown: 'teardown',
		},
		{
			name: 'teardown',
			testDir: './tests/e2e',
			testMatch: /global\.teardown\.ts/,
		},
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
			// The pictures are their own projects: they need a window of a
			// fixed size, and they are not part of what CI compares.
			testIgnore: /(-snapshots|listing-screenshots)\.spec\.ts/,
			dependencies: ['setup'],
		},
		...(PICTURES
			? [
					{
						name: 'visual',
						testMatch: /(admin|front)-snapshots\.spec\.ts/,
						use: {
							...devices['Desktop Chrome'],
							// Pinned, all three: a picture taken in a window of
							// another size, or at another pixel ratio, is a
							// picture of another screen. 1280 is the width the
							// dashboard is designed against — wide enough for the
							// rail, narrow enough to be a laptop.
							viewport: { width: 1280, height: 900 },
							deviceScaleFactor: 1,
							// The dashboard uses view transitions and this
							// plugin fades a preview while it reloads. Both are
							// right, and both are movement in a photograph.
							reducedMotion: 'reduce' as const,
						},
						dependencies: ['setup'],
					},
					{
						// The same public pages on a phone, and the dashboard's
						// screens whose stylesheet changes below 782px. A width
						// where the front end's own rules (640, 560, 480) have
						// all taken over, at a pixel ratio of one so the picture
						// is the same on every screen. Its pictures carry
						// `-mobile` in their name, beside the desktop ones.
						name: 'visual-mobile',
						testMatch: /(front|admin-mobile)-snapshots\.spec\.ts/,
						snapshotPathTemplate: 'tests/e2e/snapshots/{arg}-mobile-{platform}{ext}',
						use: {
							...devices['Desktop Chrome'],
							viewport: { width: 390, height: 844 },
							deviceScaleFactor: 1,
							isMobile: true,
							hasTouch: true,
							reducedMotion: 'reduce' as const,
						},
						dependencies: ['setup'],
					},
				]
			: []),
		...(LISTING
			? [
					{
						name: 'listing',
						testMatch: /listing-screenshots\.spec\.ts/,
						use: {
							...devices['Desktop Chrome'],
							// 1280 is what wordpress.org shows a screenshot at
							// before it asks somebody to click, and it is the
							// width the dashboard is designed against. The
							// height is generous: these are cropped to the
							// plugin's own block, so it only has to be tall
							// enough that the block is not the one scrolling.
							viewport: { width: 1280, height: 1200 },
							deviceScaleFactor: 1,
							reducedMotion: 'reduce' as const,
						},
						dependencies: ['setup'],
					},
				]
			: []),
	],
});
