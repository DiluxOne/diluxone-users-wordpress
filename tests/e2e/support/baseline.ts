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
	diluxone_users_login_slug: 'sign-in',
	diluxone_users_login_slug_old: [],
	diluxone_users_login_slug_was: '',
	diluxone_users_login_title: '',
	diluxone_users_login_intro: '',
	diluxone_users_login_legal: '',

	diluxone_users_2fa_mode: 'optional',
	diluxone_users_2fa_methods: ['email'],
	diluxone_users_2fa_link: 'auto',

	diluxone_users_sso_login: 1,
	diluxone_users_sso_register: 1,
	diluxone_users_sso_link_by_email: 1,
	diluxone_users_sso_verified_only: 0,
	diluxone_users_sso_scope: 'all',
	diluxone_users_sso_roles: [],

	// On a network, a policy the network confirmed: the specs about one that
	// has not are the ones that say so. On a single site it means nothing.
	diluxone_users_membership_confirmed: 1,
	diluxone_users_handle_enabled: 0,
	diluxone_users_handle_login: 0,

	// What the specs walk is the plugin turned on, and a fresh activation
	// turns nothing on (`make test-noop` is the check for that). These are the
	// answers an administrator who turns the plugin on would pick.
	diluxone_users_log_levels: ['access'],
	diluxone_users_notice_rules: {},
	diluxone_users_sessions_show: 1,
	diluxone_users_reset_ends_sessions: 1,
	diluxone_users_privacy_export: 1,
	diluxone_users_privacy_delete: 1,
	diluxone_users_avatar_upload: 1,
	diluxone_users_wp_register_fields: 1,
	// On a network, every site's doors lead to the hub; nothing on a single site.
	diluxone_users_network_hub: 1,

	// The fake social network is off until the SSO spec asks for it, so nobody
	// using this environment by hand finds a network called "Mock" on it.
	diluxone_e2e_sso: 0,
});
