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
