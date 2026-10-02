import { APIRequestContext, BrowserContext, Page, request as playwrightRequest } from '@playwright/test';
import { test, expect, SiteHandle, subsiteUrl } from './support';
import { Site, freshEmail } from '../support/api';
import { wp } from '../support/cli';
import { adminSaved, navigated, savePanel } from '../support/ui';
import {
	Answer,
	FORGED,
	Kind,
	NOBODY,
	Outcome,
	Row as SharedRow,
	emailOf,
	expectRefused,
	fire,
	formFields,
	kindsOf,
	metaOf,
	nonceFor,
	send,
	signedInContext,
} from '../support/refusals';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';

/**
 * The refusals of a network: who may write what, from where.
 *
 * Everything the single-site spec knocks on is knocked on here too where a
 * network changes the answer, and then what only a network has:
 *
 *   - Network Admin's own doors — the membership sync, emptying the network's
 *     log, dismissing the move's notice, every network panel — refused to a
 *     site's administrator who is not a super admin, carrying a nonce of
 *     their own;
 *   - the hub's doors sent to another site: Create the page, the sections of
 *     the account area, the fields, the social switch, a provider's form —
 *     refused even to a super admin, whose nonce is valid everywhere;
 *   - the network's and the hub's settings posted from a site's screen;
 *   - the people tools (closing sessions, a fresh code, the profile block)
 *     refused to a site's administrator against a super admin;
 *   - the account forms posted to /beta/ instead of the hub: sent to the hub,
 *     nothing written on the way;
 *   - "Join this site" forged: without a nonce, by invitation, by somebody
 *     the site's administrator removed, and by somebody signed out.
 *
 * Every test asserts the answer AND that nothing changed, read back through
 * the side door of the site the setting or the person lives on.
 */

test.use({ storageState: NETWORK_ADMIN_STATE });

const PASSWORD = 'e2e-NetRefusals-1!';
const TOTP = 'JBSWY3DPEHPK3PXP';

const VICTIM_TOKEN = 'e2e3'.repeat(16);

function token(verifier: string): Record<string, unknown> {
	const now = Math.floor(Date.now() / 1000);

	return { [verifier]: { expiration: now + 86_400, ip: '127.0.0.1', ua: 'e2e', login: now } };
}

interface Person {
	email: string;
	id: number;
	context: BrowserContext;
	api: APIRequestContext;
}

interface World {
	hubSite: Site;
	alphaSite: Site;
	betaSite: Site;
	door: APIRequestContext;
	ids: { alpha: number; beta: number };
	/** The super admin of the suite. */
	super: Person;
	/** An administrator of /alpha/, and nothing more anywhere. */
	siteAdmin: Person;
	/** Somebody of the network with no right to administer anything. */
	member: Person;
	/** A second super admin, never signed in here: whom a site's administrator would act on. */
	victim: { email: string; id: number };
	guest: APIRequestContext;
}

let world: World;

/** An address of Network Admin, relative to the network's root. */
const NETWORK = (rest: string) => `/wp-admin/network/${rest.replace(/^\//, '')}`;
/** An address of a site's dashboard. */
const ON = (slug: '' | 'alpha' | 'beta', rest: string) => `${slug ? `/${slug}` : ''}/wp-admin/${rest.replace(/^\//, '')}`;

test.beforeAll(async ({ browser }) => {
	const hubSite = await Site.open(`${NETWORK_URL}/`);
	const alphaSite = await Site.open(subsiteUrl('alpha'));
	const betaSite = await Site.open(subsiteUrl('beta'));
	const door = await playwrightRequest.newContext({ baseURL: NETWORK_URL, storageState: NOBODY });

	const victim = freshEmail('net-refused-victim');
	const made = await hubSite.makeUser({
		email: victim,
		password: PASSWORD,
		role: 'administrator',
		meta: { session_tokens: { ...token(VICTIM_TOKEN), ...token('e2e4'.repeat(16)) }, diluxone_users_totp: TOTP },
	});

	wp(['super-admin', 'add', victim]);

	const siteAdmin = freshEmail('net-refused-admin');
	const admin = await alphaSite.makeUser({ email: siteAdmin, password: PASSWORD, role: 'administrator' });

	const member = freshEmail('net-refused-member');
	const one = await hubSite.makeUser({ email: member, password: PASSWORD, role: 'subscriber', meta: { first_name: 'Before' } });

	const superContext = await browser.newContext({ baseURL: NETWORK_URL, storageState: NETWORK_ADMIN_STATE });
	const adminContext = await signedInContext(browser, NETWORK_URL, siteAdmin, PASSWORD, `${NETWORK_URL}/alpha/`);
	const memberContext = await signedInContext(browser, NETWORK_URL, member, PASSWORD);
	const superEmail = await emailOf(superContext.request, NETWORK_URL);

	world = {
		hubSite,
		alphaSite,
		betaSite,
		door,
		ids: {
			alpha: Number(wp(['eval', 'echo get_current_blog_id();'], subsiteUrl('alpha'))),
			beta: Number(wp(['eval', 'echo get_current_blog_id();'], subsiteUrl('beta'))),
		},
		super: { email: superEmail, id: (await hubSite.user(superEmail)).id, context: superContext, api: superContext.request },
		siteAdmin: { email: siteAdmin, id: admin.id, context: adminContext, api: adminContext.request },
		member: { email: member, id: one.id, context: memberContext, api: memberContext.request },
		victim: { email: victim, id: made.id },
		guest: await playwrightRequest.newContext({ baseURL: NETWORK_URL, storageState: NOBODY }),
	};
});

test.afterAll(async () => {
	if (!world) {
		return;
	}

	wp(['super-admin', 'remove', world.victim.email]);

	for (const email of [world.victim.email, world.siteAdmin.email, world.member.email]) {
		await world.hubSite.deleteUser(email);
	}

	await world.super.context.close();
	await world.siteAdmin.context.close();
	await world.member.context.close();
	await world.guest.dispose();
	await world.door.dispose();
});

function people(w: World): (who: string) => APIRequestContext {
	return (who) => (who === 'guest' ? w.guest : (w as unknown as Record<string, Person>)[who].api);
}

type Row = SharedRow<World>;

async function mails(site: Site, email: string): Promise<number> {
	return (await site.mail(email)).length;
}

async function victimState(w: World): Promise<unknown> {
	return {
		meta: await metaOf(w.door, w.victim.email, ['diluxone_users_totp']),
		sessions: (await w.hubSite.user(w.victim.email)).sessions,
		mail: await mails(w.hubSite, w.victim.email),
	};
}

/** Every row of the network's activity log, whichever site wrote it. */
function logCount(): number {
	return Number(wp(['eval', 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . diluxone_users_log_table() );'])) || 0;
}

/** How many pages a site has, drafts and all: a page made by a refused request would be one more. */
function pageCount(slug: 'alpha' | 'beta' | ''): string {
	return wp(['post', 'list', '--post_type=page', '--post_status=any', '--format=count'], slug ? subsiteUrl(slug) : `${NETWORK_URL}/`);
}

/** A Network Admin door: the super admin may, a site's administrator may not. */
const NETWORK_DOOR: Partial<Record<Kind, Outcome>> = {
	'no nonce': 'nonce',
	'forged nonce': 'nonce',
	'without the right': 'forbidden',
	'signed out': 'bad',
};

const FIELDS = [
	{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'e2e_refusal', label: 'Refusal', type: 'text', required: 0, active: 1, group: 'extra', edit: 'always' },
];

const SSO = { mock: { active: 1, id: 'e2e-client', secret: 'e2e-secret', tested: 1 } };

const ROWS: Row[] = [
	/* ── Network Admin's own doors ─────────────────────────────────── */
	{
		id: 'Membership › "Sync everyone now" (admin_post_diluxone_users_membership_sync)',
		method: 'GET',
		url: () => ON('', 'admin-post.php?action=diluxone_users_membership_sync'),
		nonce: { field: '_wpnonce', action: 'diluxone_users_membership_sync' },
		actor: 'super',
		lacking: 'siteAdmin',
		expect: NETWORK_DOOR,
		// Somebody of the hub alone, under a policy that would now add them
		// everywhere: a sync that got through would make them a member of
		// /alpha/ and /beta/.
		prepare: async ({ hub }: { hub: SiteHandle }, w) => {
			await hub.set({ diluxone_users_membership: 'invite', diluxone_users_membership_confirmed: 1 });
			const email = freshEmail('net-refused-sync');

			await w.hubSite.makeUser({ email, password: PASSWORD });
			(w as World & { syncing?: string }).syncing = email;
			await hub.set({ diluxone_users_membership: 'all' });
		},
		probe: async (w) => {
			const email = (w as World & { syncing?: string }).syncing as string;

			return { alpha: (await w.alphaSite.user(email)).member, beta: (await w.betaSite.user(email)).member };
		},
	},
	{
		id: 'Reports › empty the network’s log (admin_post_diluxone_users_log_empty_network)',
		method: 'GET',
		url: () => ON('', 'admin-post.php?action=diluxone_users_log_empty_network'),
		nonce: { field: '_wpnonce', action: 'diluxone_users_log_empty_network' },
		actor: 'super',
		lacking: 'siteAdmin',
		expect: NETWORK_DOOR,
		probe: async () => {
			const rows = logCount();

			expect(rows, 'there are rows to lose').toBeGreaterThan(0);

			return rows;
		},
	},
	{
		id: 'Reports › /beta/’s log emptied by /alpha/’s administrator (admin_post_diluxone_users_log_empty)',
		method: 'GET',
		url: () => ON('beta', 'admin-post.php?action=diluxone_users_log_empty'),
		nonce: { field: '_wpnonce', action: 'diluxone_users_log_empty' },
		actor: 'super',
		lacking: 'siteAdmin',
		expect: { 'without the right': 'forbidden', 'signed out': 'bad' },
		probe: async () => logCount(),
	},
	{
		id: 'Overview › dismissing what the sites had set differently (?diluxone_users_conflicts_seen)',
		method: 'GET',
		url: () => NETWORK('admin.php?page=diluxone-users&diluxone_users_conflicts_seen=1'),
		nonce: { field: '_wpnonce', action: 'diluxone_users_conflicts_seen' },
		actor: 'super',
		lacking: 'siteAdmin',
		expect: { 'no nonce': 'nonce', 'forged nonce': 'nonce', 'without the right': 'forbidden', 'signed out': 'to-login' },
		prepare: async ({ hub }: { hub: SiteHandle }) => hub.set({ diluxone_users_network_conflicts_seen: null }),
		probe: async (w) => w.hubSite.getOptions(['diluxone_users_network_conflicts_seen']),
	},
	{
		id: 'Fields › a field deleted in Network Admin (field action)',
		method: 'GET',
		url: () => NETWORK('admin.php?page=diluxone-users-fields&diluxone_users_action=delete&field=e2e_refusal'),
		nonce: { field: '_wpnonce', action: 'diluxone_users_field_action' },
		actor: 'super',
		lacking: 'siteAdmin',
		expect: { 'no nonce': 'nonce', 'forged nonce': 'nonce', 'without the right': 'forbidden', 'signed out': 'to-login' },
		prepare: async ({ hub }: { hub: SiteHandle }) => hub.set({ diluxone_users_fields: FIELDS }),
		probe: async (w) => w.hubSite.getOptions(['diluxone_users_fields']),
	},
	{
		id: 'Social › a provider forgotten in Network Admin (admin_action_diluxone_users_social_toggle)',
		method: 'GET',
		url: () => NETWORK('admin.php?action=diluxone_users_social_toggle&red=mock&diluxone_users_action=forget'),
		nonce: { field: '_wpnonce', action: 'diluxone_users_social_toggle' },
		actor: 'super',
		lacking: 'siteAdmin',
		expect: { 'no nonce': 'nonce', 'forged nonce': 'nonce', 'without the right': 'forbidden', 'signed out': 'to-login' },
		prepare: async ({ hub }: { hub: SiteHandle }) => hub.set({ diluxone_e2e_sso: 1, diluxone_users_sso: SSO }),
		probe: async (w) => w.hubSite.getOptions(['diluxone_users_sso']),
	},

	/* ── The hub's doors, sent to another site (even by a super admin) ─ */
	{
		id: 'Create the page, on /alpha/ (admin_post_diluxone_users_create_page)',
		method: 'GET',
		url: () => ON('alpha', 'admin-post.php?action=diluxone_users_create_page&page=diluxone_users_register_page'),
		nonce: { field: '_wpnonce', action: 'diluxone_users_create_page' },
		actor: 'super',
		lacking: 'siteAdmin',
		// The super admin's own nonce is valid on every site: the "as
		// somebody else" here is the super admin, with a valid nonce, on a
		// site that does not own the pages.
		other: { who: 'super' },
		expect: { 'forged nonce': 'nonce', 'without the right': 'forbidden', 'as somebody else': 'forbidden', 'signed out': 'bad' },
		prepare: async ({ hub }: { hub: SiteHandle }) => hub.set({ diluxone_users_register_page: 0 }),
		probe: async (w) => ({ chosen: await w.hubSite.getOptions(['diluxone_users_register_page']), alpha: pageCount('alpha'), hub: pageCount('') }),
	},
	{
		id: 'Account › a section switched off, on /alpha/ (section action)',
		method: 'GET',
		url: () => ON('alpha', 'admin.php?page=diluxone-users-account&tab=sections&diluxone_users_action=off&section=details'),
		nonce: { field: '_wpnonce', action: 'diluxone_users_section_action' },
		actor: 'super',
		lacking: 'siteAdmin',
		other: { who: 'super' },
		// Not the hub: the action does nothing, and /alpha/'s own Account
		// screen (its menu and dashboard tabs) is drawn as if nothing was
		// asked.
		expect: { 'without the right': 'ignored', 'as somebody else': 'ignored', 'signed out': 'to-login' },
		probe: async (w) => w.hubSite.getOptions(['diluxone_users_account_sections']),
	},
	{
		id: 'Account › the order of the sections, posted to /alpha/ (order form)',
		method: 'POST',
		url: () => ON('alpha', 'admin.php?page=diluxone-users-account&tab=sections'),
		fields: () => ({ 'diluxone_users_order_form[]': ['privacy', 'security', 'details', 'home'] }),
		nonce: { field: 'diluxone_users_order_form_nonce', action: 'diluxone_users_order_form' },
		actor: 'super',
		lacking: 'siteAdmin',
		other: { who: 'super' },
		expect: { 'forged nonce': 'nonce', 'without the right': 'forbidden', 'as somebody else': 'forbidden', 'signed out': 'to-login' },
		probe: async (w) => w.hubSite.getOptions(['diluxone_users_account_sections']),
	},
	{
		id: 'Account › a section’s detail, posted to /alpha/ (section form)',
		method: 'POST',
		url: () => ON('alpha', 'admin.php?page=diluxone-users-account&tab=sections'),
		fields: () => ({ 'diluxone_users_section_form[id]': 'home', 'diluxone_users_section_form[label]': 'Hacked' }),
		nonce: { field: 'diluxone_users_section_form_nonce', action: 'diluxone_users_section_form' },
		actor: 'super',
		lacking: 'siteAdmin',
		other: { who: 'super' },
		expect: { 'forged nonce': 'nonce', 'without the right': 'forbidden', 'as somebody else': 'forbidden', 'signed out': 'to-login' },
		probe: async (w) => w.hubSite.getOptions(['diluxone_users_account_sections']),
	},
	{
		id: 'Account › "Your data" switches, posted to /alpha/ (privacy form)',
		method: 'POST',
		url: () => ON('alpha', 'admin.php?page=diluxone-users-account&tab=sections'),
		fields: () => ({ diluxone_users_privacy_export_when: 'admin' }),
		nonce: { field: 'diluxone_users_privacy_nonce', action: 'diluxone_users_privacy' },
		actor: 'super',
		lacking: 'siteAdmin',
		other: { who: 'super' },
		expect: { 'forged nonce': 'nonce', 'without the right': 'forbidden', 'as somebody else': 'forbidden', 'signed out': 'to-login' },
		prepare: async ({ hub }: { hub: SiteHandle }) => hub.set({ diluxone_users_privacy_export: 1, diluxone_users_privacy_delete: 1 }),
		probe: async (w) => w.hubSite.getOptions(['diluxone_users_privacy_export', 'diluxone_users_privacy_delete', 'diluxone_users_privacy_export_when']),
	},
	{
		id: 'Fields › a field deleted, sent to /alpha/ (field action)',
		method: 'GET',
		url: () => ON('alpha', 'admin.php?page=diluxone-users-fields&diluxone_users_action=delete&field=e2e_refusal'),
		nonce: { field: '_wpnonce', action: 'diluxone_users_field_action' },
		actor: 'super',
		lacking: 'siteAdmin',
		other: { who: 'super' },
		expect: { 'without the right': 'forbidden', 'as somebody else': 'forbidden', 'signed out': 'to-login' },
		prepare: async ({ hub }: { hub: SiteHandle }) => hub.set({ diluxone_users_fields: FIELDS }),
		probe: async (w) => w.hubSite.getOptions(['diluxone_users_fields']),
	},
	{
		id: 'Fields › a field saved, posted to /alpha/ (field form)',
		method: 'POST',
		url: () => ON('alpha', 'admin.php?page=diluxone-users-fields&field=new'),
		fields: () => ({ 'diluxone_users_field[label]': 'Hacked', 'diluxone_users_field[key]': 'e2e_hacked', 'diluxone_users_field[type]': 'text' }),
		nonce: { field: 'diluxone_users_field_nonce', action: 'diluxone_users_field' },
		actor: 'super',
		lacking: 'siteAdmin',
		other: { who: 'super' },
		expect: { 'without the right': 'forbidden', 'as somebody else': 'forbidden', 'signed out': 'to-login' },
		prepare: async ({ hub }: { hub: SiteHandle }) => hub.set({ diluxone_users_fields: FIELDS }),
		probe: async (w) => w.hubSite.getOptions(['diluxone_users_fields']),
	},
	{
		id: 'Social › a provider switched off from the hub’s dashboard (admin_action_diluxone_users_social_toggle)',
		method: 'GET',
		url: () => ON('', 'admin.php?action=diluxone_users_social_toggle&red=mock&diluxone_users_action=off'),
		nonce: { field: '_wpnonce', action: 'diluxone_users_social_toggle' },
		actor: 'super',
		lacking: 'siteAdmin',
		other: { who: 'super' },
		expect: { 'forged nonce': 'nonce', 'without the right': 'forbidden', 'as somebody else': 'forbidden', 'signed out': 'to-login' },
		prepare: async ({ hub }: { hub: SiteHandle }) => hub.set({ diluxone_e2e_sso: 1, diluxone_users_sso: SSO }),
		probe: async (w) => w.hubSite.getOptions(['diluxone_users_sso']),
	},
	{
		id: 'Social › a provider’s credentials, posted to the hub’s dashboard (provider form)',
		method: 'POST',
		url: () => ON('', 'admin.php?page=diluxone-users-social&provider=mock&tab=settings'),
		fields: () => ({ diluxone_users_client_id: 'hacked-id', diluxone_users_client_secret: 'hacked-secret', diluxone_users_active: '1' }),
		nonce: { field: 'diluxone_users_provider_nonce', action: 'diluxone_users_provider' },
		actor: 'super',
		lacking: 'siteAdmin',
		other: { who: 'super' },
		expect: { 'without the right': 'forbidden', 'as somebody else': 'forbidden', 'signed out': 'to-login' },
		prepare: async ({ hub }: { hub: SiteHandle }) => hub.set({ diluxone_e2e_sso: 1, diluxone_users_sso: SSO }),
		probe: async (w) => w.hubSite.getOptions(['diluxone_users_sso']),
	},

	/* ── The network's and the hub's settings, posted from a site's screen ─ */
	{
		id: 'Security › the second step, posted to /alpha/’s dashboard (network panel)',
		method: 'POST',
		url: () => ON('alpha', 'admin.php?page=diluxone-users-security&tab=2fa'),
		fields: () => ({ diluxone_users_2fa_mode: 'off', 'diluxone_users_2fa_methods[]': 'email' }),
		nonce: { field: 'diluxone_users_panel_nonce', action: 'diluxone_users_panel_diluxone-users-security' },
		actor: 'super',
		lacking: 'siteAdmin',
		other: { who: 'super' },
		expect: { 'without the right': 'forbidden', 'as somebody else': 'forbidden', 'signed out': 'to-login' },
		probe: async (w) => w.hubSite.getOptions(['diluxone_users_2fa_mode', 'diluxone_users_2fa_methods']),
	},
	{
		id: 'Access › ways in, posted to /alpha/’s dashboard (hub panel)',
		method: 'POST',
		url: () => ON('alpha', 'admin.php?page=diluxone-users-login&tab=ways'),
		fields: () => ({ diluxone_users_login_expiry: '37', 'diluxone_users_login_method[]': 'password' }),
		nonce: { field: 'diluxone_users_panel_nonce', action: 'diluxone_users_panel_diluxone-users-login' },
		actor: 'super',
		lacking: 'siteAdmin',
		other: { who: 'super' },
		expect: { 'without the right': 'forbidden', 'as somebody else': 'forbidden', 'signed out': 'to-login' },
		probe: async (w) => w.hubSite.getOptions(['diluxone_users_login_expiry', 'diluxone_users_login_method']),
	},
	{
		id: 'Access › ways in, posted to the hub by /alpha/’s administrator (hub panel)',
		method: 'POST',
		url: () => ON('', 'admin.php?page=diluxone-users-login&tab=ways'),
		fields: () => ({ diluxone_users_login_expiry: '37', 'diluxone_users_login_method[]': 'password' }),
		nonce: { field: 'diluxone_users_panel_nonce', action: 'diluxone_users_panel_diluxone-users-login' },
		actor: 'super',
		lacking: 'siteAdmin',
		expect: { 'without the right': 'forbidden', 'signed out': 'to-login' },
		probe: async (w) => w.hubSite.getOptions(['diluxone_users_login_expiry', 'diluxone_users_login_method']),
	},
	{
		id: 'Design › the sign-in page, posted to /alpha/’s dashboard (hub panel)',
		method: 'POST',
		url: () => ON('alpha', 'admin.php?page=diluxone-users-design&tab=login'),
		fields: () => ({ diluxone_users_login_title: 'Hacked', diluxone_users_login_template: 'card' }),
		nonce: { field: 'diluxone_users_panel_nonce', action: 'diluxone_users_panel_diluxone-users-design' },
		actor: 'super',
		lacking: 'siteAdmin',
		other: { who: 'super' },
		expect: { 'without the right': 'forbidden', 'as somebody else': 'forbidden', 'signed out': 'to-login' },
		probe: async (w) => w.hubSite.getOptions(['diluxone_users_login_title', 'diluxone_users_login_template']),
	},
	{
		id: 'Notices › the e-mails, posted to /alpha/’s dashboard (hub panel)',
		method: 'POST',
		url: () => ON('alpha', 'admin.php?page=diluxone-users-notices&tab=templates'),
		fields: () => ({ 'diluxone_users_mail[login_link][subject]': 'Hacked {site}' }),
		nonce: { field: 'diluxone_users_panel_nonce', action: 'diluxone_users_panel_diluxone-users-notices' },
		actor: 'super',
		lacking: 'siteAdmin',
		other: { who: 'super' },
		expect: { 'without the right': 'forbidden', 'as somebody else': 'forbidden', 'signed out': 'to-login' },
		probe: async (w) => w.hubSite.getOptions(['diluxone_users_mail_templates']),
	},

	/* ── The people tools, by a site's administrator on a super admin ─ */
	{
		id: 'Tools › close somebody’s sessions, from /alpha/ (admin_post_diluxone_users_tools, close)',
		method: 'POST',
		url: () => ON('alpha', 'admin-post.php'),
		fields: (w) => ({ action: 'diluxone_users_tools', tool: 'close', scope: 'one', close_email: w.victim.email }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_tools' },
		actor: 'super',
		lacking: 'siteAdmin',
		expect: { 'forged nonce': 'nonce', 'without the right': 'forbidden', 'signed out': 'bad' },
		probe: victimState,
	},
	{
		id: 'Tools › close every session of the network, from /alpha/ (admin_post_diluxone_users_tools, close all)',
		method: 'POST',
		url: () => ON('alpha', 'admin-post.php'),
		fields: () => ({ action: 'diluxone_users_tools', tool: 'close', scope: 'all' }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_tools' },
		actor: 'super',
		lacking: 'siteAdmin',
		expect: { 'without the right': 'forbidden', 'signed out': 'bad' },
		probe: victimState,
	},
	{
		id: 'Tools › send a fresh code, from /alpha/ (admin_post_diluxone_users_tools, code)',
		method: 'POST',
		url: () => ON('alpha', 'admin-post.php'),
		fields: (w) => ({ action: 'diluxone_users_tools', tool: 'code', email: w.victim.email }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_tools' },
		actor: 'super',
		lacking: 'siteAdmin',
		expect: { 'forged nonce': 'nonce', 'without the right': 'forbidden', 'signed out': 'bad' },
		probe: victimState,
	},
	{
		id: 'Reports › close a super admin’s sessions, from /alpha/ (admin_post_diluxone_users_sessions_admin)',
		method: 'POST',
		url: () => ON('alpha', 'admin-post.php'),
		fields: (w) => ({ action: 'diluxone_users_sessions_admin', diluxone_users_user: String(w.victim.id) }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_sessions_admin' },
		actor: 'super',
		lacking: 'siteAdmin',
		expect: { 'no nonce': 'nonce', 'forged nonce': 'nonce', 'without the right': 'forbidden', 'signed out': 'bad' },
		probe: victimState,
	},
	{
		id: 'Users › a super admin’s second factor removed on /alpha/’s Edit user (user-edit.php)',
		method: 'POST',
		url: () => ON('alpha', 'user-edit.php'),
		fields: (w) => ({ action: 'update', user_id: String(w.victim.id), email: w.victim.email, nickname: 'victim', diluxone_users_forget_totp: '1' }),
		nonce: { field: '_wpnonce', action: (w) => `update-user_${w.victim.id}` },
		actor: 'super',
		lacking: 'siteAdmin',
		// (c) is WordPress's own refusal, before the plugin's hook fires:
		// user-edit.php stops with wp_die() and no status, which is a 500.
		expect: { 'no nonce': 'nonce', 'forged nonce': 'nonce', 'without the right': { status: 500 }, 'signed out': 'to-login' },
		probe: victimState,
	},

	/* ── Passkeys are the hub's ────────────────────────────────────── */
	{
		id: 'a passkey registered on /beta/ (wp_ajax_diluxone_users_passkeys)',
		method: 'POST',
		url: () => ON('beta', 'admin-ajax.php'),
		fields: () => ({ action: 'diluxone_users_passkeys', step: 'register-options' }),
		nonce: { field: 'nonce', action: 'diluxone_users_passkeys' },
		actor: 'member',
		other: { who: 'member' },
		// With the member's own valid nonce: told the passkeys are the hub's.
		expect: { 'forged nonce': { status: 403 }, 'as somebody else': { status: 403 } },
		prepare: async ({ hub }: { hub: SiteHandle }) => hub.set({ diluxone_users_passkey_enabled: 1 }),
		probe: async (w) => metaOf(w.door, w.member.email, ['diluxone_users_passkeys']),
		leaks: (answer) => expect(answer.body, 'no challenge handed out off the hub').not.toContain('challenge'),
	},
];

test.describe('Every network door that writes refuses what it should', () => {
	for (const row of ROWS) {
		for (const kind of kindsOf(row)) {
			const outcome = row.expect[kind] as Outcome;

			test(`${row.id} › ${kind}`, async ({ hub, alpha, beta }) => {
				await row.prepare?.({ hub, alpha, beta }, world);

				const before = await row.probe(world);
				const answer = await fire(world, row, kind, people(world));

				expectRefused(answer, outcome, `${row.id} (${kind})`);
				row.leaks?.(answer);

				expect(await row.probe(world), `${row.id} (${kind}) wrote something`).toEqual(before);
			});
		}
	}
});

/* ── The account forms, posted to another site ─────────────────────── */

/**
 * The twelve actions the hub owns (diluxone_users_hub_posts()), each posted to
 * /beta/'s admin-post.php: sent to the hub — its sign-in page for a stranger,
 * the account for somebody signed in — before the handler reads a byte, and
 * nothing written. Sent with the member's own valid nonce, which is the
 * strongest version: a nonce would get it through on the hub.
 */
const HUB_POSTS: Array<{ action: string; nonce: string; field?: string; fields: Record<string, string> }> = [
	{ action: 'diluxone_users_link_request', nonce: 'diluxone_users_login', field: 'diluxone_users_nonce', fields: { diluxone_users_email: '' } },
	{ action: 'diluxone_users_signup', nonce: 'diluxone_users_register', field: 'diluxone_users_register_nonce', fields: { diluxone_users_email: '' } },
	{ action: 'diluxone_users_reset', nonce: 'diluxone_users_reset', field: 'diluxone_users_reset_nonce', fields: { pass1: 'e2e-Hacked-9!', pass2: 'e2e-Hacked-9!' } },
	{ action: 'diluxone_users_avatar', nonce: 'diluxone_users_avatar', fields: { diluxone_users_avatar_remove: '1' } },
	{ action: 'diluxone_users_handle', nonce: 'diluxone_users_handle', fields: { diluxone_users_handle: 'hacked-name' } },
	{ action: 'diluxone_users_fields_save', nonce: 'diluxone_users_fields_save', fields: { first_name: 'Hacked', diluxone_users_group: '' } },
	{ action: 'diluxone_users_sso_unlink', nonce: 'diluxone_users_sso_unlink', fields: { diluxone_users_provider: 'mock' } },
	{ action: 'diluxone_users_security', nonce: 'diluxone_users_security', fields: { diluxone_users_security: 'code' } },
	{ action: 'diluxone_users_sessions', nonce: 'diluxone_users_sessions', fields: {} },
	{ action: 'diluxone_users_notifications', nonce: 'diluxone_users_notifications', fields: {} },
	{ action: 'diluxone_users_data_request', nonce: 'diluxone_users_data_request', fields: { diluxone_users_request: 'export' } },
	{ action: 'diluxone_users_passkey', nonce: 'diluxone_users_passkey', fields: { diluxone_users_passkey: 'x', diluxone_users_passkey_do: 'delete' } },
];

async function memberState(w: World): Promise<unknown> {
	return {
		meta: await metaOf(w.door, w.member.email, [
			'first_name',
			'diluxone_users_handle',
			'diluxone_users_notify_login',
			'diluxone_users_2fa_on',
			'diluxone_users_avatar',
		]),
		sessions: (await w.hubSite.user(w.member.email)).sessions,
		hubMail: await mails(w.hubSite, w.member.email),
		betaMail: await mails(w.betaSite, w.member.email),
	};
}

test.describe('A form of the hub’s, posted to /beta/, is sent to the hub and writes nothing', () => {
	for (const post of HUB_POSTS) {
		test(`${post.action} › signed in, with a valid nonce`, async ({ hub, beta }) => {
			await hub.set({ diluxone_users_handle_enabled: 1, diluxone_users_privacy_export: 1, diluxone_e2e_sso: 1 });

			const before = await memberState(world);
			const { nonce } = await nonceFor(world.member.api, post.nonce);
			const fields = { action: post.action, ...post.fields, [post.field ?? '_wpnonce']: nonce };

			if ('diluxone_users_email' in fields) {
				fields.diluxone_users_email = world.member.email;
			}

			const answer = await send(world.member.api, 'POST', ON('beta', 'admin-post.php'), fields);

			expect(answer.status, `${post.action} on /beta/`).toBe(302);
			expect(answer.location, 'sent to the account on the hub').toContain(hub.pages.account.url.replace(/\/$/, ''));
			expect(await memberState(world), `${post.action} on /beta/ wrote something`).toEqual(before);
			expect(beta.url).toBeTruthy();
		});

		test(`${post.action} › signed out`, async ({ hub }) => {
			const before = await memberState(world);
			const fields: Record<string, string> = { action: post.action, ...post.fields, [post.field ?? '_wpnonce']: (await nonceFor(world.guest, post.nonce)).nonce };

			if ('diluxone_users_email' in fields) {
				fields.diluxone_users_email = world.member.email;
			}

			const answer = await send(world.guest, 'POST', ON('beta', 'admin-post.php'), fields);

			expect(answer.status, `${post.action} on /beta/`).toBe(302);
			expect(answer.location, 'sent to the sign-in page on the hub').toContain(hub.pages.login.url.replace(/\/$/, ''));
			expect(await memberState(world), `${post.action} on /beta/ wrote something`).toEqual(before);
		});
	}
});

/* ── The same forms, signed out, on the hub itself ─────────────────── */

/**
 * On the hub the redirect to the hub has nowhere to send a form, and a
 * signed-out post of an action that needs a session should be refused out
 * loud, the way the single-site spec asks it.
 */
test.describe('A form that needs a session, posted signed out to the hub, is refused out loud', () => {
	for (const post of HUB_POSTS.filter((one) => !one.field)) {
		test(`${post.action} › signed out, on the hub`, async () => {
			const before = await memberState(world);
			const answer = await send(world.guest, 'POST', ON('', 'admin-post.php'), {
				action: post.action,
				...post.fields,
				_wpnonce: (await nonceFor(world.guest, post.nonce)).nonce,
			});

			expectRefused(answer, 'loud', `${post.action} signed out on the hub`);
			expect(await memberState(world), `${post.action} wrote something`).toEqual(before);
		});
	}
});

/* ── "Join this site", forged ──────────────────────────────────────── */

test.describe('"Join this site" refuses what it should', () => {
	/** Somebody of the hub alone: under "whoever asks" a member of nothing else. */
	async function joiner(hub: SiteHandle, policy: 'click' | 'invite', meta: Record<string, unknown> = {}): Promise<string> {
		await hub.set({ diluxone_users_membership: policy, diluxone_users_membership_confirmed: 1 });

		const email = freshEmail('net-refused-join');

		await world.hubSite.makeUser({ email, password: PASSWORD, meta });

		return email;
	}

	async function join(api: APIRequestContext, nonce: string | null): Promise<Answer> {
		return send(api, 'POST', ON('beta', 'admin-post.php'), { action: 'diluxone_users_join', ...(nonce === null ? {} : { _wpnonce: nonce }) });
	}

	test('without a nonce, and with one WordPress never made: 403, and not a member', async ({ browser, hub }) => {
		const email = await joiner(hub, 'click');
		const context = await signedInContext(browser, NETWORK_URL, email, PASSWORD);

		try {
			expectRefused(await join(context.request, null), 'nonce', 'join without a nonce');
			expectRefused(await join(context.request, FORGED), 'nonce', 'join with a forged nonce');
			expect((await world.betaSite.user(email)).member, 'not a member of /beta/').toBe(false);

			// The same person, the same form, with their own nonce: the
			// policy lets them, so it goes through — the refusals above were
			// the nonce's.
			const answer = await join(context.request, (await nonceFor(context.request, 'diluxone_users_join')).nonce);

			expectRefused(answer, { state: 'joined' }, 'join with a valid nonce');
			expect((await world.betaSite.user(email)).member, 'a member now').toBe(true);
		} finally {
			await context.close();
			await world.hubSite.deleteUser(email);
		}
	});

	test('by invitation, a valid form is answered join-refused and makes nobody a member', async ({ browser, hub }) => {
		const email = await joiner(hub, 'invite');
		const context = await signedInContext(browser, NETWORK_URL, email, PASSWORD);

		try {
			const answer = await join(context.request, (await nonceFor(context.request, 'diluxone_users_join')).nonce);

			expectRefused(answer, { state: 'join-refused' }, 'join by invitation');
			expect((await world.betaSite.user(email)).member, 'not a member of /beta/').toBe(false);
		} finally {
			await context.close();
			await world.hubSite.deleteUser(email);
		}
	});

	test('somebody /beta/’s administrator removed cannot join back by themselves, even under “whoever asks”', async ({ browser, hub }) => {
		const email = await joiner(hub, 'click', { diluxone_users_removed_from: [world.ids.beta] });
		const context = await signedInContext(browser, NETWORK_URL, email, PASSWORD);

		try {
			const answer = await join(context.request, (await nonceFor(context.request, 'diluxone_users_join')).nonce);

			expectRefused(answer, { state: 'join-refused' }, 'join after a removal');
			expect((await world.betaSite.user(email)).member, 'not a member of /beta/').toBe(false);
			expect((await metaOf(world.door, email, ['diluxone_users_removed_from'])).diluxone_users_removed_from, 'the removal still written').toEqual([world.ids.beta]);
		} finally {
			await context.close();
			await world.hubSite.deleteUser(email);
		}
	});

	test('signed out, the form is sent to the hub’s sign-in page and joins nobody', async ({ hub }) => {
		await hub.set({ diluxone_users_membership: 'click', diluxone_users_membership_confirmed: 1 });

		const answer = await join(world.guest, (await nonceFor(world.guest, 'diluxone_users_join')).nonce);

		expectRefused(answer, 'to-login', 'join signed out');
		expect(answer.location, 'the hub’s sign-in page').toContain(hub.pages.login.url.replace(/\/$/, ''));
	});
});

/* ── The network's panels, in Network Admin ────────────────────────── */

interface PanelRow {
	screen: string;
	tab: string;
	change: Record<string, string>;
	options: string[];
}

const PANELS: PanelRow[] = [
	{ screen: 'diluxone-users', tab: 'uninstall', change: { diluxone_users_uninstall_wipe: '1' }, options: ['diluxone_users_uninstall_wipe'] },
	{ screen: 'diluxone-users-membership', tab: 'policy', change: { diluxone_users_membership: 'invite' }, options: ['diluxone_users_membership', 'diluxone_users_membership_confirmed'] },
	{ screen: 'diluxone-users-security', tab: '2fa', change: { diluxone_users_2fa_mode: 'off' }, options: ['diluxone_users_2fa_mode', 'diluxone_users_2fa_methods'] },
	{ screen: 'diluxone-users-security', tab: 'passkeys', change: { diluxone_users_passkey_where: 'device' }, options: ['diluxone_users_passkey_where', 'diluxone_users_passkey_verify'] },
	{ screen: 'diluxone-users-security', tab: 'sessions', change: { diluxone_users_session_long_days: '99' }, options: ['diluxone_users_session_long_days', 'diluxone_users_sessions_show'] },
	{ screen: 'diluxone-users-security', tab: 'proxy', change: { diluxone_users_trusted_proxies: '10.9.9.9' }, options: ['diluxone_users_trusted_proxies', 'diluxone_users_ip_header'] },
	{ screen: 'diluxone-users-social', tab: 'general', change: { diluxone_users_sso_verified_only: '1' }, options: ['diluxone_users_sso_verified_only', 'diluxone_users_sso_link_by_email'] },
	{ screen: 'diluxone-users-reports', tab: 'logging', change: { diluxone_users_log_days: '7' }, options: ['diluxone_users_log_days', 'diluxone_users_log_levels'] },
];

const PANEL_FORM = '#diluxone-users-panel-form';

async function tamperedSave(page: Page, change: Record<string, string>, nonce: string | null): Promise<number> {
	await formFields(page, PANEL_FORM, change);

	// The form's own answer, not the first response of any kind: a request in
	// the background can come back first.
	const answer = page.waitForResponse((response) => response.request().method() === 'POST' && response.request().resourceType() === 'document');

	await navigated(page, () =>
		page.locator(PANEL_FORM).evaluate((form: HTMLFormElement, nonce: string | null) => {
			const field = form.querySelector<HTMLInputElement>('input[name="diluxone_users_panel_nonce"]');

			if (nonce === null) {
				field?.remove();
			} else if (field) {
				field.value = nonce;
			}

			HTMLFormElement.prototype.submit.call(form);
		}, nonce)
	);

	return (await answer).status();
}

test.describe('Every network panel refuses a save it should not take', () => {
	for (const row of PANELS) {
		const name = `Network Admin › ${row.screen} › ${row.tab}`;
		const url = NETWORK(`admin.php?page=${row.screen}&tab=${row.tab}`);

		for (const [kind, nonce] of [
			['no nonce', null],
			['forged nonce', FORGED],
		] as const) {
			test(`${name} › ${kind}, the real form tampered in the browser`, async ({ page, hub }) => {
				await page.goto(url);
				await expect(page.locator(PANEL_FORM), 'the panel draws its form').toBeAttached();

				const before = await hub.site.getOptions(row.options);
				const status = await tamperedSave(page, row.change, nonce);

				expect(status, `${name} (${kind})`).toBe(200);
				await expect(adminSaved(page), `${name} (${kind}): congratulated itself`).toHaveCount(0);
				expect(await hub.site.getOptions(row.options), `${name} (${kind}) wrote something`).toEqual(before);
			});
		}

		test(`${name} › a site’s administrator, with a valid nonce of their own`, async ({ page, hub }) => {
			await page.goto(url);

			const fields = await formFields(page, PANEL_FORM, row.change);
			const before = await hub.site.getOptions(row.options);

			fields.diluxone_users_panel_nonce = [(await nonceFor(world.siteAdmin.api, `diluxone_users_panel_${row.screen}`)).nonce];

			const answer = await send(world.siteAdmin.api, 'POST', url, fields);

			expectRefused(answer, 'forbidden', `${name} (a site’s administrator)`);
			expect(await hub.site.getOptions(row.options), `${name} (a site’s administrator) wrote something`).toEqual(before);
		});

		test(`${name} › signed out`, async ({ page, hub }) => {
			await page.goto(url);

			const fields = await formFields(page, PANEL_FORM, row.change);
			const before = await hub.site.getOptions(row.options);

			fields.diluxone_users_panel_nonce = [(await nonceFor(world.guest, `diluxone_users_panel_${row.screen}`)).nonce];

			const answer = await send(world.guest, 'POST', url, fields);

			expectRefused(answer, 'to-login', `${name} (signed out)`);
			expect(await hub.site.getOptions(row.options), `${name} (signed out) wrote something`).toEqual(before);
		});
	}
});

/* ── The network's doors on the hub's own screen ───────────────────── */

test.describe('The hub’s Access › Ways in draws the network’s doors and saves none of them', () => {
	/**
	 * The passkey box of this form is forced in network-admin.spec.ts; this is
	 * the other network key the same form draws: social sign-in, unticked and
	 * sent anyway by a super admin. The hub's own setting in the form saves,
	 * so the save ran; the network's was left out.
	 */
	test('social sign-in forced off and sent from the hub’s screen stays on', async ({ page, hub }) => {
		await hub.set({ diluxone_users_sso_login: 1, diluxone_users_login_method: 'both', diluxone_users_login_expiry: 15 });

		await page.goto(`${hub.url}wp-admin/admin.php?page=diluxone-users-login&tab=ways`);

		const box = page.locator('input[name="diluxone_users_sso_login"]');

		await expect(box, 'drawn, and not the hub’s to change').toBeDisabled();
		await box.evaluate((input: HTMLInputElement) => {
			input.disabled = false;
			input.checked = false;
		});
		await page.locator('input[name="diluxone_users_login_expiry"]').fill('38');
		await savePanel(page);

		const stored = await hub.site.getOptions(['diluxone_users_sso_login', 'diluxone_users_login_expiry']);

		expect(Number(stored.diluxone_users_sso_login), 'the network’s social sign-in, forced off from a site’s screen').toBe(1);
		expect(Number(stored.diluxone_users_login_expiry), 'the hub’s own setting in the same form saved').toBe(38);
	});
});
