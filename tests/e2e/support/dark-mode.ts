import { Page } from '@playwright/test';

/**
 * Dark mode, as a reader meets it: the colour of a piece of text against the
 * ground actually painted behind it, and the WCAG ratio between the two.
 *
 * Every colour goes through a one-pixel canvas, so whatever the browser
 * computed (`rgb()`, `color(srgb …)`, `oklab()` from a `color-mix()`) comes
 * back as the same four numbers.
 */

/** The settings that make the site the dark-mode stand-in of the e2e mu-plugin. */
export const DARK_SITE = {
	diluxone_e2e_dark: 1,
	diluxone_users_styles: 1,
	diluxone_users_colors: 'theme',
};

/** The ground the stand-in paints in dark mode, as the browser reports it. */
export const DARK_GROUND = 'rgb(21, 23, 28)';

/** Turns the theme's own switch: `is-dark` on <html>, as a toggle in a header does. */
export async function themeSwitch(page: Page, on: boolean): Promise<void> {
	await page.evaluate((dark) => document.documentElement.classList.toggle('is-dark', dark), on);
}

export interface Reading {
	what: string;
	text: string;
	ground: string;
	ratio: number;
}

/**
 * Every visible element the selector finds, with its text colour, the ground
 * painted behind it and the ratio between them.
 *
 * `property` reads another of its colours in place of the text's, such as
 * `borderTopColor` for an edge.
 */
export function readings(page: Page, selector: string, property: 'color' | 'borderTopColor' = 'color'): Promise<Reading[]> {
	return page.evaluate(([query, which]) => {
		const canvas = document.createElement('canvas');
		canvas.width = canvas.height = 1;
		const pen = canvas.getContext('2d', { willReadFrequently: true })!;

		const channels = (colour: string): [number, number, number, number] => {
			pen.clearRect(0, 0, 1, 1);
			pen.fillStyle = '#000';
			pen.fillStyle = colour;
			pen.fillRect(0, 0, 1, 1);
			const [r, g, b, a] = pen.getImageData(0, 0, 1, 1).data;

			return [r, g, b, a / 255];
		};

		// The grounds from the element up, laid over each other until one
		// covers everything; the canvas is white when nothing does.
		const groundOf = (element: Element | null): [number, number, number] => {
			const layers: Array<[number, number, number, number]> = [];

			for (let node = element; node; node = node.parentElement) {
				const layer = channels(getComputedStyle(node).backgroundColor);

				if (layer[3] > 0) {
					layers.push(layer);
				}
				if (layer[3] >= 1) {
					break;
				}
			}

			let painted: [number, number, number] = layers.length && layers[layers.length - 1][3] >= 1 ? [0, 0, 0] : [255, 255, 255];

			for (const [r, g, b, a] of layers.reverse()) {
				painted = [r * a + painted[0] * (1 - a), g * a + painted[1] * (1 - a), b * a + painted[2] * (1 - a)];
			}

			return painted;
		};

		const luminance = ([r, g, b]: number[]): number => {
			const linear = (c: number) => {
				const v = c / 255;

				return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
			};

			return 0.2126 * linear(r) + 0.7152 * linear(g) + 0.0722 * linear(b);
		};

		const css = (c: number[]) => `rgb(${c.slice(0, 3).map(Math.round).join(', ')})`;

		return Array.from(document.querySelectorAll(query))
			.filter((element) => (element as HTMLElement).offsetParent !== null || getComputedStyle(element).position === 'fixed')
			.map((element) => {
				const ground = groundOf(element);
				const [r, g, b, a] = channels(getComputedStyle(element)[which]);
				const text = [r * a + ground[0] * (1 - a), g * a + ground[1] * (1 - a), b * a + ground[2] * (1 - a)];
				const one = luminance(text);
				const two = luminance(ground);

				return {
					what: `${element.tagName.toLowerCase()} “${(element.textContent ?? '').trim().slice(0, 40)}”`,
					text: css(text),
					ground: css(ground),
					ratio: (Math.max(one, two) + 0.05) / (Math.min(one, two) + 0.05),
				};
			});
	}, [selector, property] as const);
}

/** The ground painted behind one element, as `rgb(r, g, b)`. */
export async function groundBehind(page: Page, selector: string): Promise<string> {
	const [first] = await readings(page, selector);

	return first?.ground ?? '';
}

/** The readings under the bar, by name, so a failure says which heading it was. */
export function unreadable(all: Reading[], bar = 4.5): string[] {
	return all.filter((one) => one.ratio < bar).map((one) => `${one.what}: ${one.text} on ${one.ground} (${one.ratio.toFixed(2)}:1)`);
}

/** The switches of the e2e mu-plugin's stand-in theme with one of its own (`diluxone_e2e_switch`). */
export type SwitchKind = 'data-theme' | 'data-tema' | 'class';

/**
 * The settings that make the site the stand-in with a switch of its own, the
 * sign-in page of its own on, and Dark mode follows › the site's own switch,
 * with the signals as stored: where they go, a space, and what they are.
 */
export function switchSite(kind: SwitchKind, dark: string, light = '', extra: Record<string, unknown> = {}): Record<string, unknown> {
	return {
		diluxone_e2e_switch: kind,
		diluxone_e2e_switch_off_door: 0,
		diluxone_users_styles: 1,
		diluxone_users_colors: 'theme',
		diluxone_users_dark_follows: 'site',
		diluxone_users_dark_signal: dark,
		diluxone_users_light_signal: light,
		diluxone_users_login_template: 'own',
		diluxone_users_login_slug: 'sign-in',
		diluxone_users_login_slug_old: [],
		...extra,
	};
}

/**
 * The visitor flips the site's switch, kept the way the stand-in keeps it
 * (`e2e-scheme` in the browser: 'dark', 'light', or '' for as the device),
 * on a page of the site, which is opened again so its script puts it there;
 * and waits for the plugin to have measured that page in that scheme.
 */
export async function siteScheme(page: Page, url: string, scheme: 'dark' | 'light' | ''): Promise<void> {
	await page.goto(url);
	await page.evaluate((v) => (v ? localStorage.setItem('e2e-scheme', v) : localStorage.removeItem('e2e-scheme')), scheme);
	await page.reload();

	if (scheme) {
		await page.waitForFunction(
			(v) => Object.keys(localStorage).some((k) => k.endsWith(':scheme') && localStorage.getItem(k) === v),
			scheme,
		);
	}
}

/** The text the stand-in paints in dark mode, as the browser reports it. */
export const DARK_TEXT = 'rgb(230, 232, 236)';

/** One of the plugin's colours, as it stands on <html>: `token(page, 'text')`. */
export function token(page: Page, role: string): Promise<string> {
	return page.evaluate((r) => getComputedStyle(document.documentElement).getPropertyValue(`--diluxone-users-${r}`).trim(), role);
}

/** The form of the sign-in page of its own. */
export const DOOR_FORM = '.diluxone-users-login-frame--own .diluxone-users-login';

/**
 * Which scheme an element is drawn in, as a reader tells: light letters on a
 * dark ground, or the other way round.
 */
export async function drawnIn(page: Page, selector: string): Promise<'dark' | 'light' | ''> {
	const [first] = await readings(page, selector);

	if (!first) {
		return '';
	}

	const light = (css: string) => {
		const [r, g, b] = (css.match(/\d+/g) ?? []).map(Number);

		return (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;
	};

	return light(first.ground) < light(first.text) ? 'dark' : 'light';
}

/**
 * A theme whose own stylesheet flips its colours under
 * `prefers-color-scheme: dark` (the stand-in of `repro/issue-113`): it paints
 * `html` and `body` with its variables and names the two grounds, as the
 * plugin prints them for a theme whose palette says which colour is the
 * ground. The text roles are left to what the page measures.
 */
export const STAND_IN_THEME = `<style id="stand-in-theme">
:root{--cst-fondo:#ffffff;--cst-fondo-alt:#f5f6f8;--cst-texto:#1b1d22;--cst-texto-tenue:#4a505c}
@media (prefers-color-scheme: dark){:root{--cst-fondo:#15171c;--cst-fondo-alt:#1c1f26;--cst-texto:#e6e8ec;--cst-texto-tenue:#aeb4c0}}
html,body{background:var(--cst-fondo);color:var(--cst-texto)}
:root{--diluxone-users-surface:var(--cst-fondo);--diluxone-users-surface-alt:var(--cst-fondo-alt)}
</style>`;

/** Serves the page at `pathname` with the stand-in theme in its head. */
export async function wearStandIn(page: Page, pathname: string): Promise<void> {
	await page.route(
		(url) => url.pathname === pathname,
		async (route) => {
			const response = await route.fetch();

			await route.fulfill({ response, body: (await response.text()).replace('</head>', `${STAND_IN_THEME}</head>`) });
		}
	);
}

/** What is wrong with what the selector finds: missing, or under the bar against the ground behind it. */
export async function unreadableOn(page: Page, name: string, selector: string, bar = 4.5): Promise<string[]> {
	const all = await readings(page, selector);

	return all.length === 0 ? [`${name}: not on the page`] : unreadable(all, bar).map((line) => `${name}: ${line}`);
}
