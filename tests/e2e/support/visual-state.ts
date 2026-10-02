import { Site } from './api';

/**
 * The state every picture is taken in, said once.
 *
 * A baseline is only an assertion if the screen it photographs is drawn from
 * the same data every time. The settings the specs depend on are pinned by
 * `baseline.ts` for every suite; this pins the rest of what the pictures show
 * — the fields, the sections, the colours, the providers, the design — and
 * the people the reports and the overview count. Without it a picture of
 * Design or of Fields is a picture of whatever the development site was set
 * to last, and it fails on the next machine for a reason nobody can act on.
 *
 * Both halves are written by the picture specs before they shoot, through the
 * `options` fixture, so the development site gets its own settings back when
 * the test ends.
 */

/**
 * Every setting of the plugin the pictures can show, apart from the ones
 * `baseline.ts` already pins for every suite and the three pages, whose ids
 * belong to the site. `null` is a setting that is not stored, which is a
 * value too: the plugin's default.
 */
export const VISUAL_OPTIONS: Record<string, unknown> = {
	diluxone_users_2fa_roles: [],
	diluxone_users_account_action: "0",
	diluxone_users_account_avatar: "1",
	diluxone_users_account_bar_gap: "",
	diluxone_users_account_body_pad: "",
	diluxone_users_account_cover: "",
	diluxone_users_account_cover_image: "0",
	diluxone_users_account_cover_kind: "color",
	diluxone_users_account_ground: "",
	diluxone_users_account_header: "1",
	diluxone_users_account_layout: null,
	diluxone_users_account_nav_align: "start",
	diluxone_users_account_nav_bottom: "",
	diluxone_users_account_nav_left: "",
	diluxone_users_account_nav_right: "",
	diluxone_users_account_nav_small: "scroll",
	diluxone_users_account_nav_style: "pills",
	diluxone_users_account_nav_top: "",
	diluxone_users_account_row_pad: "",
	diluxone_users_account_row_w: "",
	diluxone_users_account_sections: {"home": {"position": 10, "label": "Inicio", "slug": "home", "intro": "Todo lo tuyo, en un lugar.", "content": "", "placement": "after", "roles": [], "visibility": "all"}, "details": {"position": 20}, "accounts": {"position": 30}, "security": {"position": 40}, "notifications": {"position": 50}, "privacy": {"position": 60}},
	diluxone_users_account_since: "1",
	diluxone_users_account_template: "plain",
	diluxone_users_account_width: "contained",
	diluxone_users_admin_bar: "wp",
	diluxone_users_admin_bar_keep_admins: "1",
	diluxone_users_admin_bar_roles: [],
	diluxone_users_admin_bar_scope: "all",
	diluxone_users_avatar_gravatar: "0",
	diluxone_users_avatar_initials: "1",
	diluxone_users_avatar_max_kb: "2048",
	diluxone_users_avatar_upload: null,
	diluxone_users_bar_account: "0",
	diluxone_users_button_icons: "0",
	diluxone_users_button_style: "solid",
	diluxone_users_color_map: {"accent": "accent-1", "accent-ink": "base", "text": "contrast", "muted": "", "surface": "base", "surface-alt": "", "border": ""},
	diluxone_users_colors: "own",
	diluxone_users_fields: [{"key": "first_name", "label": "First name", "type": "text", "help": "", "options": [], "required": 1, "group": "main", "active": 1}, {"key": "last_name", "label": "Last name", "type": "text", "help": "", "options": [], "required": 0, "group": "main", "active": 1}, {"key": "phone", "label": "Phone", "type": "tel", "group": "contact", "required": 1, "active": 1, "edit": "always", "help": "With the area code, so we can reach you about a booking."}, {"key": "birthday", "label": "Date of birth", "type": "date", "group": "personal", "required": 0, "active": 1, "edit": "limited", "edit_max": 1}, {"key": "country", "label": "Country", "type": "country", "group": "contact", "required": 0, "active": 1, "edit": "always"}, {"key": "membership", "label": "Membership", "type": "select", "group": "personal", "options": ["Full member", "Family", "Student", "Honorary"], "required": 1, "active": 1, "edit": "never", "help": "Set by the office. Ask at the desk to change it."}, {"key": "dietary", "label": "Anything we should know for the asado", "type": "textarea", "group": "personal", "required": 0, "active": 1, "edit": "always"}, {"key": "newsletter", "label": "Send me the monthly newsletter", "type": "checkbox", "group": "preferences", "required": 0, "active": 1, "edit": "always"}],
	diluxone_users_handle_cooldown: null,
	diluxone_users_handle_max: null,
	diluxone_users_handle_min: null,
	diluxone_users_handle_reserved: null,
	diluxone_users_handle_spaces: null,
	diluxone_users_home_cards_off: ["accounts"],
	diluxone_users_ip_header: null,
	diluxone_users_log_days: "90",
	diluxone_users_log_levels: null,
	diluxone_users_login_image: "0",
	diluxone_users_login_logo: "0",
	diluxone_users_login_open: "",
	diluxone_users_login_order: null,
	diluxone_users_login_panel_foot: "",
	diluxone_users_login_panel_logo: "0",
	diluxone_users_login_panel_points: "",
	diluxone_users_login_panel_text: "",
	diluxone_users_login_panel_title: "Una cuenta.\r\nSin contraseñas.",
	diluxone_users_login_side: "left",
	diluxone_users_mail_last: {"ok": 0, "time": 1789400368, "error": "Dirección no válida:  (From): wordpress@localhost"},
	diluxone_users_mail_templates: {"es_AR": {"login_link": {"subject": "Tu entrada al club", "body": "Entrá: {link} — dura {minutes} minutos."}}},
	diluxone_users_membership: null,
	diluxone_users_menu_location: null,
	diluxone_users_menu_style: null,
	diluxone_users_notice_rules: null,
	diluxone_users_notice_style: "bar",
	diluxone_users_passkey_verify: null,
	diluxone_users_passkey_where: null,
	diluxone_users_privacy_delete: null,
	diluxone_users_privacy_delete_link: "account",
	diluxone_users_privacy_delete_when: "confirm",
	diluxone_users_privacy_export: null,
	diluxone_users_privacy_export_file: "account",
	diluxone_users_privacy_export_link: "account",
	diluxone_users_privacy_export_when: "confirm",
	diluxone_users_register_done: "",
	diluxone_users_register_intro: "",
	diluxone_users_register_title: null,
	diluxone_users_sent_icon: "plain",
	diluxone_users_sent_note: "",
	diluxone_users_sent_title: "",
	diluxone_users_session_long_days: "30",
	diluxone_users_session_short_days: "2",
	diluxone_users_sessions_show: "1",
	diluxone_users_sso: {"google": {"active": 1, "id": "e2e-google-id", "secret": "e2e-google-secret", "tested": 1}, "microsoft": {"active": 1, "id": "e2e-microsoft-id", "secret": "e2e-microsoft-secret", "tested": 1}, "facebook": {"active": 1, "id": "e2e-facebook-id", "secret": "e2e-facebook-secret", "tested": 1}},
	diluxone_users_sso_blocked_roles: [],
	diluxone_users_sso_button_columns: null,
	diluxone_users_sso_button_shape: null,
	diluxone_users_sso_button_show: null,
	diluxone_users_sso_button_skin: null,
	diluxone_users_sso_button_text: null,
	diluxone_users_style_accent: "#2271b1",
	diluxone_users_style_border: "",
	diluxone_users_style_control: "",
	diluxone_users_style_radius: "6",
	diluxone_users_styles: "1",
	diluxone_users_trusted_proxies: null,
	diluxone_users_uninstall_wipe: null,
	diluxone_users_wp_login_bg: null,
	diluxone_users_wp_login_brand: null,
	diluxone_users_wp_login_logo: "0",
	diluxone_users_wp_profile: "allow",
	diluxone_users_wp_profile_roles: [],
	diluxone_users_wp_profile_scope: "all",
};

/** One of the people the reports and the overview are photographed with. */
interface Person {
	email: string;
	name: string;
	role: string;
	/** The plugin's own data on the account, and the names. */
	meta: Record<string, unknown>;
	/** Signed in somewhere right now, or every session run out. */
	live: boolean;
}

const MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15';
const IPHONE =
	'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';

/**
 * The cast: ten people with an account, a public name, a social account, an
 * authenticator app or a passkey in the proportions the overview shows.
 *
 * They carry `@example.com`, not the suite's domain, because they are not the
 * suite's: they are the development site's example people, and the teardown
 * that deletes every `@e2e.test` account must leave them. An account missing
 * is made; one that exists keeps its password and is given back this data.
 */
export const CAST: Person[] = [
	{
		email: 'ana@example.com',
		name: 'Ana Figueroa',
		role: 'subscriber',
		live: true,
		meta: {
			first_name: 'Ana',
			last_name: 'Figueroa',
			diluxone_users_passkeys: [
				{ id: 'aXHUlnfdjBuAXl60ZGQM', label: 'Ana’s iPhone', created: 1786746534, used: 1788474534, ua: IPHONE },
				{ id: 'AQsz8a1nsuNlWbQK4dGJ', label: 'MacBook Pro', created: 1788301734, used: 1788992934, ua: MAC },
			],
			diluxone_users_totp: 'JBSWY3DPEHPK3PXP',
			diluxone_users_2fa_on: '1',
			diluxone_users_2fa_pending: null,
			diluxone_users_sso_google: 'google|10947362514',
			diluxone_users_handle: 'ana',
			diluxone_users_field_phone: '+54 261 555 0142',
			diluxone_users_field_country: 'AR',
			diluxone_users_field_membership: 'Full member',
		},
	},
	{
		email: 'marcos@example.com',
		name: 'Marcos Ruiz',
		role: 'subscriber',
		live: true,
		meta: { first_name: 'Marcos', last_name: 'Ruiz', diluxone_users_sso_google: 'google|55831209471' },
	},
	{
		email: 'lucia@example.com',
		name: 'Lucía Pereyra',
		role: 'editor',
		live: true,
		meta: {
			first_name: 'Lucía',
			last_name: 'Pereyra',
			diluxone_users_totp: 'KRSXG5CTMVRXEZLU',
			diluxone_users_2fa_on: '1',
			diluxone_users_sso_microsoft: 'microsoft|8f3c2a',
			diluxone_users_handle: 'lu',
		},
	},
	{
		email: 'tomas@example.com',
		name: 'Tomás Vega',
		role: 'subscriber',
		live: true,
		meta: {
			first_name: 'Tomás',
			last_name: 'Vega',
			diluxone_users_passkeys: [
				{
					id: 'kYdIqiiFGMJm3jJD1PgJ',
					label: 'Pixel 8',
					created: 1788647334,
					used: 1789165734,
					ua: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36',
				},
			],
			diluxone_users_handle: 'tomi',
			diluxone_users_totp_pending: 'WLXUN2BSDI6Y6NNQDSBU434AFKUDNXTQ',
		},
	},
	{
		email: 'sofia@example.com',
		name: 'Sofía Molina',
		role: 'subscriber',
		live: true,
		meta: {
			first_name: 'Sofía',
			last_name: 'Molina',
			diluxone_users_sso_facebook: 'facebook|771204',
			diluxone_users_2fa_on: '1',
			diluxone_users_handle: 'sofi',
		},
	},
	{
		email: 'joaquin@example.com',
		name: 'Joaquín Sosa',
		role: 'subscriber',
		live: true,
		meta: { first_name: 'Joaquín', last_name: 'Sosa' },
	},
	{
		email: 'valentina@example.com',
		name: 'Valentina Díaz',
		role: 'author',
		live: true,
		meta: {
			first_name: 'Valentina',
			last_name: 'Díaz',
			diluxone_users_sso_linkedin: 'linkedin|Rk82nQ',
			diluxone_users_totp: 'MFRGGZDFMZTWQ2LK',
		},
	},
	{
		email: 'bruno@example.com',
		name: 'Bruno Alcaraz',
		role: 'subscriber',
		live: true,
		meta: {
			first_name: 'Bruno',
			last_name: 'Alcaraz',
			diluxone_users_passkeys: [
				{
					id: 'tGqq0CY6HW7PJHWatLhE',
					label: 'Windows Hello',
					created: 1788992934,
					used: 1789252134,
					ua: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
				},
			],
		},
	},
	{
		email: 'camila@example.com',
		name: 'Camila Ferrer',
		role: 'subscriber',
		live: true,
		meta: { first_name: 'Camila', last_name: 'Ferrer', diluxone_users_sso_google: 'google|33012947710', diluxone_users_handle: 'cami' },
	},
	{
		email: 'nico@example.com',
		name: 'Nicolás Paz',
		role: 'subscriber',
		live: false,
		meta: { first_name: 'Nicolás', last_name: 'Paz' },
	},
];

/**
 * Two sessions, a laptop and a phone, counted from now.
 *
 * Sessions are the one thing about a person that is about time, so they are
 * the one thing written relative to the moment the picture is taken: a
 * session that was open yesterday is closed next month, and a report whose
 * "open" turns to "expired" on its own is a baseline that rots. The dates
 * themselves are masked; what is photographed is that there are two, from
 * where, on what, and whether they are open.
 */
function sessions(index: number, live: boolean): Record<string, Record<string, unknown>> {
	const now = Math.floor(Date.now() / 1000);
	const day = 86_400;
	const shift = live ? 0 : -40 * day;
	const token = (n: number) => `${String(index).padStart(2, '0')}${n}`.padEnd(64, 'e');

	return {
		[token(1)]: { login: now + shift - 2 * day, expiration: now + shift + 12 * day, ip: '181.44.20.7', diluxone_users_ip: '181.44.20.7', ua: MAC },
		[token(2)]: { login: now + shift - 3600, expiration: now + shift + 29 * day, ip: '181.44.20.7', diluxone_users_ip: '181.44.20.7', ua: IPHONE },
	};
}

/**
 * Writes the settings and the cast.
 *
 * @param set The `options` fixture's `set`, so every setting goes back to
 *            what the site had when the test ends.
 */
export async function pinVisualState(site: Site, set: (values: Record<string, unknown>) => Promise<void>): Promise<void> {
	await set(VISUAL_OPTIONS);

	for (const [index, person] of CAST.entries()) {
		await site.makeUser({
			email: person.email,
			name: person.name,
			role: person.role,
			keep_password: true,
			meta: { ...person.meta, session_tokens: sessions(index, person.live) },
		});
	}
}
