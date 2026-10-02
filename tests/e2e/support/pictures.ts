import type { Locator, Page } from '@playwright/test';

/**
 * What the picture specs share: what is painted over, how a picture is
 * named, and when a screen has stopped moving.
 *
 * Three specs photograph the plugin — the dashboard's screens, the public
 * pages, and the network's — and a mask or a wait decided in one of them and
 * not the others is a picture that fails on one suite for a reason the other
 * two already solved.
 */

/**
 * What is painted over before the picture is taken.
 *
 * A mask keeps the element's box and fills it, so a block that changes SIZE
 * still shows up as a difference — which is the point. It is only the content
 * that is being forgiven, never the geometry. Selectors that match nothing on
 * a given screen cost nothing.
 */
export const MOVES_BY_ITSELF = [
	// The report of who is signed in: when they signed in, when it expires,
	// and how many sessions they have open. All three change while you look.
	'.diluxone-users-list td:nth-child(2)',
	'.diluxone-users-list td:nth-child(3)',
	'.diluxone-users-list__num',
	// The environment table: PHP and WordPress versions, the site's paths.
	'.diluxone-users-summary td code',
	// Somebody's photograph, which comes from Gravatar or from the theme.
	'img.avatar',
	// On the public pages: the month somebody joined, how long ago a session
	// started, when a passkey was used, when a request was made.
	'.diluxone-users-account__since',
	'.diluxone-users-session__what span',
	'.diluxone-users-requests td:first-child',
];

/**
 * What moves by itself inside a preview.
 *
 * The Design tabs draw the account area in a frame, as the administrator
 * looking at it would meet it: their own counts — how many sessions they
 * have open, which every run of the suite adds to — and the month they
 * joined. The frame's box is photographed; those words are not.
 */
const MOVES_IN_A_PREVIEW = ['.diluxone-users-card-summary__value', '.diluxone-users-card-summary__note', '.diluxone-users-account__since', 'img.avatar'];

/** Everything above, as locators on one page and inside its previews, plus what a single picture adds. */
export function masks(page: Page, extra: string[] = []): Locator[] {
	return [
		...[...MOVES_BY_ITSELF, ...extra].map((one) => page.locator(one)),
		...MOVES_IN_A_PREVIEW.map((one) => page.frameLocator('iframe').first().locator(one)),
	];
}

/** The picture's name: the slug and the tab, never a translated title. */
export function pictureOf(name: string): string {
	return `${name.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '')}.png`;
}

/**
 * Waits until the screen has stopped becoming itself.
 *
 * Fonts first — a screen photographed before its font arrives is a picture of
 * the fallback — then the frames, because the design tabs draw the real
 * sign-in page in an iframe, and then a pair of animation frames so the last
 * layout pass is over. `toHaveScreenshot` keeps shooting until two shots
 * match, so this is about getting there sooner, not about getting there.
 */
export async function settled(page: Page): Promise<void> {
	await page.evaluate(() => document.fonts.ready.then(() => undefined));

	await Promise.all(page.frames().map((frame) => frame.waitForLoadState('load').catch(() => undefined)));

	await page.evaluate(() => {
		window.scrollTo(0, 0);

		return new Promise<void>((done) => requestAnimationFrame(() => requestAnimationFrame(() => done())));
	});
}
