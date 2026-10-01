/**
 * The settings every spec is written against, on whichever site it runs.
 *
 * Its own module because two setups write it: the single-site one on the dev
 * site, and the network one on each site it makes. A spec is the same spec on
 * both, so the ground it stands on has to be the same ground.
 */

/**
 * The starting point every spec is written against.
 *
 * A spec that needs something else changes it through the `options` fixture,
 * which puts it back when the test ends — so this is what each of them finds.
 */
export const BASELINE = (pages: { login: number; register: number; account: number }) => ({
	diluxone_users_login_page: pages.login,
	diluxone_users_register_page: pages.register,
	diluxone_users_account_page: pages.account,

	diluxone_users_login_method: 'both',
	diluxone_users_wp_screens: 'auto',
	diluxone_users_lost_password: 'wp',
	diluxone_users_login_register: 1,
	diluxone_users_register_form: 0,
	diluxone_users_login_role: 'subscriber',
	diluxone_users_login_expiry: 15,
	// A throttle counted in minutes would make a suite that asks for several
	// links a suite that waits. It is one second, which still proves the key
	// exists without anybody watching a clock.
	diluxone_users_login_throttle: 1,
	// Every way in on the screen at once. Left unsaid this is 'auto', which
	// with a link, a password and the social buttons is three ways in and so
	// tabs — and then every spec that is about something else entirely fills
	// a box inside a closed tab and waits a minute for it to appear. The
	// arrangement is a subject of its own: login-ways.spec.ts and the picture
	// of the tabbed sign-in page ask for it by name, and they are the only
	// two that should.
	diluxone_users_login_layout: 'stack',
	diluxone_users_login_template: 'plain',
	diluxone_users_login_title: '',
	diluxone_users_login_intro: '',
	diluxone_users_login_legal: '',

	diluxone_users_2fa_mode: 'optional',
	diluxone_users_2fa_methods: ['totp', 'email'],
	diluxone_users_2fa_link: 'auto',
	diluxone_users_2fa_remember_days: 30,
	diluxone_users_2fa_scope: 'all',

	diluxone_users_sso_login: 1,
	diluxone_users_sso_register: 1,
	diluxone_users_sso_link_by_email: 1,
	diluxone_users_sso_verified_only: 0,
	diluxone_users_sso_scope: 'all',
	diluxone_users_sso_roles: [],

	diluxone_users_passkey_enabled: 0,

	// On a network, a policy the network confirmed: the specs about one that
	// has not are the ones that say so. On a single site it means nothing.
	diluxone_users_membership_confirmed: 1,
	diluxone_users_handle_enabled: 0,
	diluxone_users_handle_login: 0,

	// The fake social network is off until the SSO spec asks for it, so nobody
	// using this environment by hand finds a network called "Mock" on it.
	diluxone_e2e_sso: 0,
});
