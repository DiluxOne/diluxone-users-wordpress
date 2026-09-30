import { adminUrl } from './ui';

/**
 * Every screen the dashboard has, and every tab on it.
 *
 * It lives in `support/` and not inside one spec because three suites need
 * the same list, and a list kept in three places is a list that is right in
 * one of them. The behaviour spec walks it to prove each tab answers; the
 * layout spec walks it to measure each tab; the snapshot spec walks it to
 * photograph each tab.
 *
 * A screen with a single tab draws no tab strip — `diluxone_users_tabs()`
 * returns early below two — so its slug is written down here anyway: the URL
 * takes it, and nothing on the page says what it is called. That the list is
 * complete is not left to trust either: the layout spec reads the tab strip
 * of every screen and fails if it finds a tab that is not in here, so a tab
 * added next month joins these suites the day it is added.
 */
export const SCREENS: Record<string, string[]> = {
	'diluxone-users': ['usage', 'doors', 'asked'],
	'diluxone-users-login': ['summary', 'page', 'ways', 'arrangement', 'register', 'messages'],
	'diluxone-users-security': ['summary', '2fa', 'passkeys', 'sessions', 'proxy'],
	'diluxone-users-social': ['providers', 'general'],
	'diluxone-users-account': ['summary', 'page', 'sections', 'handle', 'menu', 'dashboard'],
	'diluxone-users-fields': ['list', 'usage'],
	'diluxone-users-design': ['brand', 'login', 'register', 'account', 'social', 'photo', 'wp'],
	'diluxone-users-notices': ['summary', 'rules', 'templates'],
	'diluxone-users-reports': ['sessions', 'activity', 'logging'],
	'diluxone-users-status': ['status', 'tools', 'lockout'],
};

/**
 * The screens of Network Admin, on a network where the plugin is on for every
 * site: the network's settings, and nothing of any one site's.
 *
 * The same slugs as a site's screens — they are the same screens, drawn from
 * the same panels, and only the tabs that belong to the network are drawn —
 * and the same rule as above: the layout spec of the network suite reads each
 * tab strip and fails on a tab that is not written down here.
 */
export const NETWORK_SCREENS: Record<string, string[]> = {
	'diluxone-users': ['network', 'uninstall'],
	'diluxone-users-membership': ['policy'],
	'diluxone-users-security': ['summary', '2fa', 'passkeys', 'sessions', 'proxy'],
	'diluxone-users-social': ['providers', 'general'],
	'diluxone-users-fields': ['list', 'usage'],
	'diluxone-users-reports': ['network-activity', 'logging'],
};

/**
 * What a site of that network keeps in its own menu.
 *
 * The main site — the hub — keeps the screens people sign in, register and
 * keep their account on; any other site keeps only what is its own. Every
 * other screen is in Network Admin, or on the hub.
 */
export const SITE_SCREENS_ON_A_NETWORK: Record<'hub' | 'site', string[]> = {
	hub: [
		'diluxone-users',
		'diluxone-users-login',
		'diluxone-users-account',
		'diluxone-users-design',
		'diluxone-users-notices',
		'diluxone-users-reports',
		'diluxone-users-status',
	],
	site: ['diluxone-users', 'diluxone-users-account', 'diluxone-users-reports', 'diluxone-users-status'],
};

/** One tab, as the specs that walk every tab want it. */
export interface AdminTab {
	screen: string;
	tab: string;
	/** How a test names it: the slug and the tab, never a translated title. */
	name: string;
	/** The address, ready for `page.goto()`. */
	url: string;
}

/**
 * The screens that draw what the site is doing rather than what it is set to.
 *
 * A list of who is signed in is a different list every hour, and a picture is
 * the one thing that cannot forgive that. So the registry — not the spec —
 * says which address makes such a screen reproducible: one seeded person,
 * found by an address that was seeded with them.
 */
const PINNED: Record<string, Record<string, string>> = {
	'diluxone-users-reports › sessions': { s: 'ana@example.com' },
	'diluxone-users-reports › activity': { s: 'ana@example.com' },
	'diluxone-users-reports › network-activity': { s: 'ana@example.com' },
};

/** Every tab of every screen, flattened, in the order the menu has them. */
export function adminTabs(): AdminTab[] {
	const tabs: AdminTab[] = [];

	for (const [screen, list] of Object.entries(SCREENS)) {
		for (const tab of list) {
			const name = `${screen} › ${tab}`;

			tabs.push({ screen, tab, name, url: adminUrl(screen, tab, PINNED[name] ?? {}) });
		}
	}

	return tabs;
}

/** Every tab of Network Admin's screens, with its address under /wp-admin/network/. */
export function networkAdminTabs(): AdminTab[] {
	const tabs: AdminTab[] = [];

	for (const [screen, list] of Object.entries(NETWORK_SCREENS)) {
		for (const tab of list) {
			tabs.push({
				screen,
				tab,
				name: `network › ${screen} › ${tab}`,
				url: adminUrl(screen, tab, PINNED[`${screen} › ${tab}`] ?? {}).replace('/wp-admin/', '/wp-admin/network/'),
			});
		}
	}

	return tabs;
}
