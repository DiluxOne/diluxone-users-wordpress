import { Page } from '@playwright/test';
import { groundBehind, readings } from './dark-mode';

/**
 * The plugin on someone else's theme: activating one for a spec and putting
 * the old one back, Astra's dark palette, and the contrast of the centred
 * card's edge against the page.
 *
 * The theme is the site's, not the test's, so whatever changes it has to run
 * one test at a time (`mode: 'serial'`) and undo itself in `afterAll`.
 *
 * Every helper takes the WP-CLI it runs through: `devWp` on the single site,
 * the network suite's `wp(…, url)` on a site of the network.
 */

export type Cli = (args: string[]) => string;

/** Astra, as `.wp-env.json` installs it on both sites. */
export const ASTRA = 'astra';

/** WordPress's default theme, which is what wp-env leaves active. */
export const DEFAULT_THEME = 'twentytwentyfive';

/** Astra's own "Dark" palette (palette_4 in its presets). */
export const ASTRA_DARK = ['#0085FF', '#0177E3', '#FFFFFF', '#E7F6FF', '#212A37', '#0F172A', '#4F5B62', '#070614', '#222222'];

/**
 * Activates a theme and hands back what puts the one before it back.
 *
 * On a network a site can only switch to a theme the network allows, so
 * `network` enables it there first, and disables it again afterwards if it
 * was not enabled before.
 */
export function activateTheme(cli: Cli, slug: string, network = false): () => void {
	const before = cli(['theme', 'list', '--status=active', '--field=name']).split('\n')[0].trim();
	const enable = network && cli(['theme', 'list', `--name=${slug}`, '--field=enabled']).trim() !== 'network';

	if (enable) {
		cli(['theme', 'enable', slug, '--network']);
	}
	if (before !== slug) {
		cli(['theme', 'activate', slug]);
	}

	return () => {
		if (before && before !== slug) {
			cli(['theme', 'activate', before]);
		}
		if (enable) {
			cli(['theme', 'disable', slug, '--network']);
		}
	};
}

/** An option as WP-CLI stores it, or null when it was never written. */
function optionJson(cli: Cli, key: string): Record<string, unknown> | null {
	try {
		const out = cli(['option', 'get', key, '--format=json']);

		return out ? (JSON.parse(out) as Record<string, unknown>) : null;
	} catch {
		return null;
	}
}

/**
 * Sets Astra's global palette to its dark one and hands back what puts the
 * `astra-settings` option back as it was, or deletes it if it was not there.
 */
export function astraDark(cli: Cli): () => void {
	const before = optionJson(cli, 'astra-settings');
	const settings = { ...(before ?? {}), 'global-color-palette': { palette: ASTRA_DARK } };

	cli(['option', 'update', 'astra-settings', JSON.stringify(settings), '--format=json']);

	return () => {
		if (before) {
			cli(['option', 'update', 'astra-settings', JSON.stringify(before), '--format=json']);
		} else {
			cli(['option', 'delete', 'astra-settings']);
		}
	};
}

/** The centred card's box, and the frame it sits in. */
export const CARD_FRAME = '.diluxone-users-login-frame--card';
export const CARD_BOX = `${CARD_FRAME} .diluxone-users-login-frame__box`;

/** `rgb(r, g, b)` to its WCAG relative luminance. */
function luminance(css: string): number {
	const [r, g, b] = (css.match(/\d+(\.\d+)?/g) ?? ['0', '0', '0']).slice(0, 3).map(Number);
	const linear = (c: number) => {
		const v = c / 255;

		return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
	};

	return 0.2126 * linear(r) + 0.7152 * linear(g) + 0.0722 * linear(b);
}

/** The WCAG contrast ratio between two `rgb()` colours. */
export function contrast(one: string, two: string): number {
	const a = luminance(one);
	const b = luminance(two);

	return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

export interface Edge {
	edge: string;
	page: string;
	ratio: number;
}

/**
 * The card's edge (its computed `border-top-color`, laid over what is behind
 * it) against the ground painted behind the frame, which is the page's.
 */
export async function cardEdge(page: Page): Promise<Edge> {
	const [box] = await readings(page, CARD_BOX, 'borderTopColor');
	const ground = await groundBehind(page, CARD_FRAME);
	const edge = box?.text ?? '';

	return { edge, page: ground, ratio: edge && ground ? contrast(edge, ground) : 0 };
}
