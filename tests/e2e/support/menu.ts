import { Locator } from '@playwright/test';

/**
 * The plugin's items in the site's own menu, measured as a reader sees them.
 */

/** What the browser paints a link with, and what the theme paints its own buttons with. */
export async function buttonPaint(link: Locator) {
	return link.evaluate((a) => {
		const probe = document.createElement('a');
		probe.className = 'wp-element-button';
		probe.textContent = 'x';
		document.body.appendChild(probe);
		const theme = getComputedStyle(probe);
		const themes = { background: theme.backgroundColor, color: theme.color };
		probe.remove();

		const own = getComputedStyle(a);
		return {
			link: { background: own.backgroundColor, color: own.color, border: own.borderTopColor, borderWidth: parseFloat(own.borderTopWidth) },
			theme: themes,
		};
	});
}

/** WCAG's contrast ratio between two `rgb()` colours as the browser reports them. */
export function contrast(one: string, two: string): number {
	const luminance = (colour: string): number => {
		const [r, g, b] = (colour.match(/\d+(\.\d+)?/g) ?? ['0', '0', '0']).slice(0, 3).map((n) => {
			const c = Number(n) / 255;
			return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
		});
		return 0.2126 * r + 0.7152 * g + 0.0722 * b;
	};
	const [a, b] = [luminance(one), luminance(two)];
	return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

/**
 * The e2e menu laid out the way most themes lay theirs: one row, flex, no gap
 * of its own, across the width of the page. The theme wp-env ships draws the
 * shortcode's list as a plain list, which is no menu bar at all.
 */
export const FLEX_MENU =
	'nav.diluxone-e2e-menu{width:100%}' +
	'nav.diluxone-e2e-menu > ul{display:flex;gap:0;margin:0;padding:0;list-style:none;width:100%}' +
	'nav.diluxone-e2e-menu > ul > li{margin:0;padding:0}' +
	'nav.diluxone-e2e-menu .sub-menu{display:none}';

/** Where the boxes are: of the bar, and of each of its top-level items and their links. */
export async function menuBoxes(page: import('@playwright/test').Page) {
	return page.locator('nav.diluxone-e2e-menu > ul').evaluate((bar) => {
		const box = (element: Element) => {
			const r = element.getBoundingClientRect();
			return { left: r.left, right: r.right, top: r.top };
		};
		return {
			bar: box(bar),
			items: Array.from(bar.children).map((li) => ({
				classes: li.className,
				li: box(li),
				link: box(li.querySelector(':scope > a')!),
				margin: getComputedStyle(li).marginInlineStart,
			})),
		};
	});
}
