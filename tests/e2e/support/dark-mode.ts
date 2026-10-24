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
