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
