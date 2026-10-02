import { APIRequestContext, BrowserContext, Page, request as playwrightRequest } from '@playwright/test';
import { test, expect, Options } from '../support/fixtures';
import { E2E_DOMAIN, Site, freshEmail } from '../support/api';
import { adminSaved, navigated } from '../support/ui';
import {
	Answer,
	FORGED,
	Kind,
	Row as SharedRow,
	fire,
	kindsOf,
	NOBODY,
	Outcome,
	devWp,
	emailOf,
	expectRefused,
	formFields,
	metaOf,
	nonceFor,
	send,
	signedInContext,
} from '../support/refusals';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Every door that writes, knocked on by somebody who should not get through.
 *
 * The inventory is the code's: every `admin_post_*` and `wp_ajax_*` action the
 * plugin hooks, every settings panel registered with
 * `diluxone_users_register_panel()` that saves, the forms the sections and
 * fields screens post to `admin_init`, the social switch, the Status › Tools
 * buttons, the preview and its "Try" token, and the three public forms that
 * carry a nonce of their own. Each is sent
 *
 *   (a) with no nonce,
 *   (b) with a nonce WordPress never made,
 *   (c) by a role without the capability, carrying a nonce that is valid for
 *       THAT role — so what stops it is the capability, not the nonce,
 *   (d) by somebody signed out,
 *
 * and, where the thing belongs to a person, (e) by another signed-in person
 * with their own valid nonce. Every test asserts the answer — the status, or
 * where the request was sent — AND that whatever it would have written is
 * what it was before, read back through the side door.
 *
 * The answers are the code's: a failed nonce is 403 (check_admin_referer),
 * "You are not allowed to do this." is 403, "You have to sign in first." is
 * 401, and admin-post.php / admin-ajax.php answer 400 to an action nobody
 * hooked for this caller. A panel's save runs only behind a valid nonce, so a
 * panel POST without one is a page view: 200, no success notice.
 */

test.use({ storageState: ADMIN_STATE });

const PASSWORD = 'e2e-Refusals-1!';
const TOTP = 'JBSWY3DPEHPK3PXP';

/** The fields the details form saves, pinned so the site's own list decides nothing here. */
const FIELDS = [
	{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'e2e_refusal', label: 'Refusal', type: 'text', required: 0, active: 1, group: 'extra', edit: 'always' },
];

/** A session row of WordPress's own shape, under a verifier this spec knows. */
function token(verifier: string): Record<string, unknown> {
	const now = Math.floor(Date.now() / 1000);

	return { [verifier]: { expiration: now + 86_400, ip: '127.0.0.1', ua: 'e2e', login: now } };
}

const VICTIM_TOKEN = 'e2e0'.repeat(16);
const SUBSCRIBER_TOKEN = 'e2e1'.repeat(16);

interface Person {
	email: string;
	id: number;
	context: BrowserContext;
	api: APIRequestContext;
}

/** Everybody the refusals are sent by, or would be about. */
interface World {
	base: string;
	site: Site;
	/** The side door's own request context, for the meta route. */
	door: APIRequestContext;
	admin: Person;
	editor: Person;
	subscriber: Person;
	/** An administrator nobody here signs in as: the person a forged tool or report button would act on. */
	victim: { email: string; id: number };
	/** No cookie at all. */
	guest: APIRequestContext;
}

let world: World;

test.beforeAll(async ({ browser }, info) => {
	const base = String(info.project.use.baseURL).replace(/\/$/, '');
	const site = await Site.open(base);
	const door = await playwrightRequest.newContext({ baseURL: base });

	const victim = freshEmail('refused-victim');
	const made = await site.makeUser({
		email: victim,
		password: PASSWORD,
		role: 'administrator',
		meta: {
			session_tokens: { ...token(VICTIM_TOKEN), ...token('e2e2'.repeat(16)) },
			diluxone_users_totp: TOTP,
			diluxone_users_passkeys: [{ id: 'e2e-victim-key', label: 'Theirs', public: '', alg: -7, sign: 0 }],
		},
	});

	const subscriber = freshEmail('refused-sub');
	const sub = await site.makeUser({
		email: subscriber,
		password: PASSWORD,
		role: 'subscriber',
		meta: {
			first_name: 'Before',
			session_tokens: token(SUBSCRIBER_TOKEN),
			diluxone_users_passkeys: [{ id: 'e2e-refusal-key', label: 'Before', public: '', alg: -7, sign: 0 }],
			diluxone_users_sso_mock: 'e2e-linked',
			diluxone_users_avatar: 999999,
		},
	});

	const editor = freshEmail('refused-ed');
	const ed = await site.makeUser({ email: editor, password: PASSWORD, role: 'editor' });

	const adminContext = await browser.newContext({ baseURL: base, storageState: ADMIN_STATE });
	const subContext = await signedInContext(browser, base, subscriber, PASSWORD);
	const edContext = await signedInContext(browser, base, editor, PASSWORD);
	const adminEmail = await emailOf(adminContext.request, base);

	world = {
		base,
		site,
		door,
		admin: { email: adminEmail, id: (await site.user(adminEmail)).id, context: adminContext, api: adminContext.request },
		editor: { email: editor, id: ed.id, context: edContext, api: edContext.request },
		subscriber: { email: subscriber, id: sub.id, context: subContext, api: subContext.request },
		victim: { email: victim, id: made.id },
		guest: await playwrightRequest.newContext({ baseURL: base, storageState: NOBODY }),
	};
});

test.afterAll(async () => {
	if (!world) {
		return;
	}

	for (const email of [world.victim.email, world.subscriber.email, world.editor.email]) {
		await world.site.deleteUser(email);
	}

	await world.admin.context.close();
	await world.editor.context.close();
	await world.subscriber.context.close();
	await world.guest.dispose();
	await world.door.dispose();
});

/* ── What a row is ─────────────────────────────────────────────────── */

type Row = SharedRow<World>;

const ADMIN_POST = '/wp-admin/admin-post.php';
const ADMIN_AJAX = '/wp-admin/admin-ajax.php';

/** How many messages the site tried to send to an address. */
async function mails(w: World, email: string): Promise<number> {
	return (await w.site.mail(email)).length;
}

/** The subscriber's own things, the ones the account forms write. */
const SUBSCRIBER_META = [
	'first_name',
	'diluxone_users_avatar',
	'diluxone_users_handle',
	'diluxone_users_notify_login',
	'diluxone_users_notify_security',
	'diluxone_users_2fa_on',
	'diluxone_users_passkeys',
	'diluxone_users_sso_mock',
];

async function subscriberState(w: World): Promise<unknown> {
	return {
		meta: await metaOf(w.door, w.subscriber.email, SUBSCRIBER_META),
		sessions: (await w.site.user(w.subscriber.email)).sessions,
		mail: await mails(w, w.subscriber.email),
	};
}

async function victimState(w: World): Promise<unknown> {
	return {
		meta: await metaOf(w.door, w.victim.email, ['diluxone_users_totp', 'diluxone_users_passkeys']),
		sessions: (await w.site.user(w.victim.email)).sessions,
		mail: await mails(w, w.victim.email),
	};
}

/** Rows of the activity log the report shows for one address, read as the administrator. */
async function logRows(w: World, who: string): Promise<number> {
	const html = await (await w.admin.api.get(`/wp-admin/admin.php?page=diluxone-users-reports&tab=activity&s=${encodeURIComponent(who)}`)).text();

	return (html.match(/<tr[^>]*data-diluxone-users-event=/g) ?? []).length;
}

/** A front handler a signed-in person may use: refused without its nonce, and loudly to a stranger. */
const FRONT: Partial<Record<Kind, Outcome>> = { 'no nonce': 'nonce', 'forged nonce': 'nonce', 'signed out': 'loud' };

/** A dashboard handler behind manage_options. */
const ADMIN: Partial<Record<Kind, Outcome>> = {
	'no nonce': 'nonce',
	'forged nonce': 'nonce',
	'without the right': 'forbidden',
	'signed out': 'bad',
};

/** A form a dashboard screen posts to admin_init: refused by the nonce, the role or the sign-in. */
const SCREEN: Partial<Record<Kind, Outcome>> = {
	'no nonce': 'nonce',
	'forged nonce': 'nonce',
	'without the right': 'forbidden',
	'signed out': 'to-login',
};

const ROWS: Row[] = [
	/* ── The account area (admin_post_*, a session needed) ─────────── */
	{
		id: 'details form (admin_post_diluxone_users_fields_save)',
		method: 'POST',
		url: () => ADMIN_POST,
		fields: () => ({ action: 'diluxone_users_fields_save', diluxone_users_group: '', first_name: 'Hacked', e2e_refusal: 'Hacked' }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_fields_save' },
		actor: 'subscriber',
		expect: FRONT,
		prepare: async (options) => options.set({ diluxone_users_fields: FIELDS }),
		probe: subscriberState,
	},
	{
		id: 'photo removed (admin_post_diluxone_users_avatar)',
		method: 'POST',
		url: () => ADMIN_POST,
		fields: () => ({ action: 'diluxone_users_avatar', diluxone_users_avatar_remove: '1' }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_avatar' },
		actor: 'subscriber',
		expect: FRONT,
		probe: subscriberState,
	},
	{
		id: 'public name (admin_post_diluxone_users_handle)',
		method: 'POST',
		url: () => ADMIN_POST,
		fields: () => ({ action: 'diluxone_users_handle', diluxone_users_handle: 'hacked-name' }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_handle' },
		actor: 'subscriber',
		expect: FRONT,
		prepare: async (options) => options.set({ diluxone_users_handle_enabled: 1 }),
		probe: subscriberState,
	},
	{
		id: 'notifications (admin_post_diluxone_users_notifications)',
		method: 'POST',
		url: () => ADMIN_POST,
		// Nothing ticked: a save that went through would write '0' for both.
		fields: () => ({ action: 'diluxone_users_notifications' }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_notifications' },
		actor: 'subscriber',
		expect: FRONT,
		probe: subscriberState,
	},
	{
		id: 'two-step switched on (admin_post_diluxone_users_security, on)',
		method: 'POST',
		url: () => ADMIN_POST,
		fields: () => ({ action: 'diluxone_users_security', diluxone_users_security: 'on', diluxone_users_code: '000000' }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_security' },
		actor: 'subscriber',
		expect: FRONT,
		probe: subscriberState,
	},
	{
		id: 'a code mailed (admin_post_diluxone_users_security, code)',
		method: 'POST',
		url: () => ADMIN_POST,
		fields: () => ({ action: 'diluxone_users_security', diluxone_users_security: 'code' }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_security' },
		actor: 'subscriber',
		expect: FRONT,
		probe: subscriberState,
	},
	{
		id: 'other sessions closed (admin_post_diluxone_users_sessions)',
		method: 'POST',
		url: () => ADMIN_POST,
		fields: () => ({ action: 'diluxone_users_sessions' }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_sessions' },
		actor: 'subscriber',
		// (e): one session of the VICTIM's, named by its verifier, from the
		// subscriber's own valid form. The answer is the usual one; the
		// victim's sessions are not touched.
		other: { fields: () => ({ action: 'diluxone_users_sessions', diluxone_users_session: VICTIM_TOKEN }) },
		expect: { ...FRONT, 'as somebody else': { state: 'sessions' } },
		probe: async (w) => ({ mine: await subscriberState(w), theirs: await victimState(w) }),
	},
	{
		id: 'a passkey removed (admin_post_diluxone_users_passkey, delete)',
		method: 'POST',
		url: () => ADMIN_POST,
		fields: () => ({ action: 'diluxone_users_passkey', diluxone_users_passkey: 'e2e-refusal-key', diluxone_users_passkey_do: 'delete' }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_passkey' },
		actor: 'subscriber',
		// (e): the victim's key, from the subscriber's own form: back to the
		// security section with no notice, nothing removed, nobody mailed.
		other: { fields: () => ({ action: 'diluxone_users_passkey', diluxone_users_passkey: 'e2e-victim-key', diluxone_users_passkey_do: 'delete' }) },
		expect: { ...FRONT, 'as somebody else': { status: 302, to: /\/security\/?$/ } },
		probe: async (w) => ({ mine: await subscriberState(w), theirs: await victimState(w) }),
	},
	{
		id: 'a passkey renamed (admin_post_diluxone_users_passkey, rename)',
		method: 'POST',
		url: () => ADMIN_POST,
		fields: () => ({
			action: 'diluxone_users_passkey',
			diluxone_users_passkey: 'e2e-refusal-key',
			diluxone_users_passkey_do: 'rename',
			diluxone_users_passkey_label: 'Hacked',
		}),
		nonce: { field: '_wpnonce', action: 'diluxone_users_passkey' },
		actor: 'subscriber',
		other: {
			fields: () => ({
				action: 'diluxone_users_passkey',
				diluxone_users_passkey: 'e2e-victim-key',
				diluxone_users_passkey_do: 'rename',
				diluxone_users_passkey_label: 'Hacked',
			}),
		},
		expect: { ...FRONT, 'as somebody else': { state: 'passkeyname' } },
		probe: async (w) => ({ mine: await subscriberState(w), theirs: await victimState(w) }),
	},
	{
		id: 'a copy of my data asked for (admin_post_diluxone_users_data_request)',
		method: 'POST',
		url: () => ADMIN_POST,
		fields: () => ({ action: 'diluxone_users_data_request', diluxone_users_request: 'export' }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_data_request' },
		actor: 'subscriber',
		expect: FRONT,
		prepare: async (options) => options.set({ diluxone_users_privacy_export: 1 }),
		probe: subscriberState,
	},
	{
		id: 'a social account unlinked (admin_post_diluxone_users_sso_unlink)',
		method: 'POST',
		url: () => ADMIN_POST,
		fields: () => ({ action: 'diluxone_users_sso_unlink', diluxone_users_provider: 'mock' }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_sso_unlink' },
		actor: 'subscriber',
		expect: FRONT,
		prepare: async (options) => options.set({ diluxone_e2e_sso: 1 }),
		probe: subscriberState,
	},
	{
		id: '"Yes, delete my account" (admin_post_diluxone_users_confirm_close)',
		method: 'POST',
		url: () => ADMIN_POST,
		fields: () => ({ action: 'diluxone_users_confirm_close', diluxone_users_request_id: '1', diluxone_users_key: 'x' }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_confirm_close' },
		actor: 'subscriber',
		// Not one of the hub's actions: nothing is hooked for a guest, so 400.
		expect: { 'no nonce': 'nonce', 'forged nonce': 'nonce', 'signed out': 'bad' },
		probe: async (w) => ({ mine: await subscriberState(w), exists: (await w.site.user(w.subscriber.email)).exists }),
	},
	{
		id: 'the export file downloaded (admin_post_diluxone_users_data_download)',
		method: 'GET',
		url: () => `${ADMIN_POST}?action=diluxone_users_data_download&request=1`,
		nonce: { field: '_wpnonce', action: 'diluxone_users_data_download' },
		actor: 'subscriber',
		// (e) is its own test below, with a request that is really somebody else's.
		expect: { 'no nonce': 'nonce', 'forged nonce': 'nonce', 'signed out': 'bad' },
		probe: subscriberState,
		leaks: (answer) => expect(answer.type, 'a refusal is not a file').not.toContain('zip'),
	},
	{
		id: 'is this public name free (wp_ajax_diluxone_users_handle_check)',
		method: 'POST',
		url: () => ADMIN_AJAX,
		fields: (w) => ({ action: 'diluxone_users_handle_check', handle: w.editor.email.split('@')[0] }),
		nonce: { field: 'nonce', action: 'diluxone_users_handle_check' },
		actor: 'subscriber',
		// No `nopriv`: admin-ajax.php answers a guest 0 with a 400.
		expect: { 'no nonce': 'nonce', 'forged nonce': 'nonce', 'signed out': 'bad' },
		prepare: async (options) => options.set({ diluxone_users_handle_enabled: 1 }),
		probe: subscriberState,
		leaks: (answer) => expect(answer.body, 'whether a name is taken is not told').not.toContain('"free"'),
	},
	{
		id: 'a passkey registered: its options (wp_ajax_diluxone_users_passkeys, register-options)',
		method: 'POST',
		url: () => ADMIN_AJAX,
		fields: () => ({ action: 'diluxone_users_passkeys', step: 'register-options' }),
		nonce: { field: 'nonce', action: 'diluxone_users_passkeys' },
		actor: 'subscriber',
		// The guest carries a nonce valid for a stranger — the one the
		// sign-in page hands out — and is still refused: registering needs a
		// session.
		expect: { 'no nonce': { status: 403 }, 'forged nonce': { status: 403 }, 'signed out': { status: 403 } },
		prepare: async (options) => options.set({ diluxone_users_passkey_enabled: 1 }),
		probe: subscriberState,
		leaks: (answer) => expect(answer.body, 'no challenge handed out').not.toContain('challenge'),
	},
	{
		id: 'a passkey registered: the key (wp_ajax_diluxone_users_passkeys, register)',
		method: 'POST',
		url: () => ADMIN_AJAX,
		fields: () => ({
			action: 'diluxone_users_passkeys',
			step: 'register',
			id: 'aGFja2Vk',
			publicKey: 'aGFja2Vk',
			clientDataJSON: 'e30',
			authenticatorData: 'aGFja2Vk',
			algorithm: '-7',
			label: 'Hacked',
		}),
		nonce: { field: 'nonce', action: 'diluxone_users_passkeys' },
		actor: 'subscriber',
		expect: { 'no nonce': { status: 403 }, 'forged nonce': { status: 403 }, 'signed out': { status: 403 } },
		prepare: async (options) => options.set({ diluxone_users_passkey_enabled: 1 }),
		probe: subscriberState,
	},
	{
		id: 'a passkey sign-in: the challenge (wp_ajax_nopriv_diluxone_users_passkeys, login-options)',
		method: 'POST',
		url: () => ADMIN_AJAX,
		fields: () => ({ action: 'diluxone_users_passkeys', step: 'login-options' }),
		nonce: { field: 'nonce', action: 'diluxone_users_passkeys' },
		actor: 'guest',
		expect: { 'no nonce': { status: 403 }, 'forged nonce': { status: 403 } },
		prepare: async (options) => options.set({ diluxone_users_passkey_enabled: 1 }),
		probe: subscriberState,
		leaks: (answer) => expect(answer.body, 'no challenge handed out').not.toContain('challenge'),
	},
	{
		id: 'a passkey sign-in: the assertion (wp_ajax_nopriv_diluxone_users_passkeys, login)',
		method: 'POST',
		url: () => ADMIN_AJAX,
		fields: () => ({
			action: 'diluxone_users_passkeys',
			step: 'login',
			id: 'ZTJlLXJlZnVzYWwta2V5',
			clientDataJSON: 'e30',
			authenticatorData: 'aGFja2Vk',
			signature: 'aGFja2Vk',
		}),
		nonce: { field: 'nonce', action: 'diluxone_users_passkeys' },
		actor: 'guest',
		expect: { 'no nonce': { status: 403 }, 'forged nonce': { status: 403 } },
		prepare: async (options) => options.set({ diluxone_users_passkey_enabled: 1 }),
		probe: subscriberState,
		leaks: (answer) => expect(answer.body, 'no session handed out').not.toContain('"success":true'),
	},

	/* ── The public forms with a nonce of their own (nopriv) ───────── */
	{
		id: 'a sign-in link asked for (admin_post_nopriv_diluxone_users_link_request)',
		method: 'POST',
		url: () => ADMIN_POST,
		fields: (w) => ({ action: 'diluxone_users_link_request', diluxone_users_email: w.subscriber.email }),
		nonce: { field: 'diluxone_users_nonce', action: 'diluxone_users_login' },
		actor: 'guest',
		expect: { 'no nonce': { state: 'error' }, 'forged nonce': { state: 'error' } },
		probe: subscriberState,
	},
	{
		id: 'an account registered (admin_post_nopriv_diluxone_users_signup)',
		method: 'POST',
		url: () => ADMIN_POST,
		fields: () => ({ action: 'diluxone_users_signup', diluxone_users_email: `refused-signup${E2E_DOMAIN}` }),
		nonce: { field: 'diluxone_users_register_nonce', action: 'diluxone_users_register' },
		actor: 'guest',
		expect: { 'no nonce': { state: 'error' }, 'forged nonce': { state: 'error' } },
		prepare: async (options) => options.set({ diluxone_users_login_register: 1, diluxone_users_register_form: 1 }),
		probe: async (w) => ({ made: (await w.site.user(`refused-signup${E2E_DOMAIN}`)).exists, mail: await mails(w, `refused-signup${E2E_DOMAIN}`) }),
	},
	{
		id: 'a new password chosen (admin_post_nopriv_diluxone_users_reset)',
		method: 'POST',
		url: () => ADMIN_POST,
		fields: () => ({ action: 'diluxone_users_reset', pass1: 'e2e-Hacked-9!', pass2: 'e2e-Hacked-9!' }),
		nonce: { field: 'diluxone_users_reset_nonce', action: 'diluxone_users_reset' },
		actor: 'guest',
		expect: { 'no nonce': { state: 'error' }, 'forged nonce': { state: 'error' } },
		probe: subscriberState,
	},
	{
		id: '"Join this site" on a single site (admin_post_diluxone_users_join is a network’s only)',
		method: 'POST',
		url: () => ADMIN_POST,
		fields: () => ({ action: 'diluxone_users_join' }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_join' },
		actor: 'subscriber',
		// Not hooked at all here: WordPress's own 400 to everybody.
		expect: { 'forged nonce': 'bad', 'signed out': 'bad' },
		probe: async (w) => ({ roles: (await w.site.user(w.subscriber.email)).roles }),
	},

	/* ── Status › Tools, Reports, the preview (admin_post_*, manage_options) ─ */
	{
		id: 'Tools › the wipe box (admin_post_diluxone_users_tools, wipe)',
		method: 'POST',
		url: () => ADMIN_POST,
		fields: () => ({ action: 'diluxone_users_tools', tool: 'wipe', wipe: '1' }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_tools' },
		actor: 'admin',
		lacking: 'editor',
		expect: ADMIN,
		probe: async (w) => w.site.getOptions(['diluxone_users_uninstall_wipe']),
	},
	{
		id: 'Tools › close one person’s sessions (admin_post_diluxone_users_tools, close)',
		method: 'POST',
		url: () => ADMIN_POST,
		fields: (w) => ({ action: 'diluxone_users_tools', tool: 'close', scope: 'one', close_email: w.victim.email }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_tools' },
		actor: 'admin',
		lacking: 'editor',
		expect: ADMIN,
		probe: victimState,
	},
	{
		id: 'Tools › send a fresh code (admin_post_diluxone_users_tools, code)',
		method: 'POST',
		url: () => ADMIN_POST,
		fields: (w) => ({ action: 'diluxone_users_tools', tool: 'code', email: w.victim.email }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_tools' },
		actor: 'admin',
		lacking: 'editor',
		expect: ADMIN,
		probe: victimState,
	},
	{
		id: 'Tools › restore a settings file (admin_post_diluxone_users_tools, import)',
		method: 'POST',
		url: () => ADMIN_POST,
		multipart: () => ({
			action: 'diluxone_users_tools',
			tool: 'import',
			file: {
				name: 'settings.json',
				mimeType: 'application/json',
				buffer: Buffer.from(JSON.stringify({ plugin: 'diluxone-users', settings: { diluxone_users_login_title: 'Hacked' } })),
			},
		}),
		nonce: { field: '_wpnonce', action: 'diluxone_users_tools' },
		actor: 'admin',
		lacking: 'editor',
		expect: ADMIN,
		probe: async (w) => w.site.getOptions(['diluxone_users_login_title']),
	},
	{
		id: 'Tools › download the settings (admin_post_diluxone_users_tools, export)',
		method: 'POST',
		url: () => ADMIN_POST,
		fields: () => ({ action: 'diluxone_users_tools', tool: 'export' }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_tools' },
		actor: 'admin',
		lacking: 'editor',
		expect: ADMIN,
		probe: async () => null,
		leaks: (answer) => expect(answer.body, 'no settings handed out').not.toContain('diluxone_users_'),
	},
	{
		id: 'Status › send a test e-mail (admin_post_diluxone_users_mail_test)',
		method: 'POST',
		url: () => ADMIN_POST,
		fields: () => ({ action: 'diluxone_users_mail_test' }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_mail_test' },
		actor: 'admin',
		lacking: 'editor',
		expect: ADMIN,
		probe: async (w) => ({ admin: await mails(w, w.admin.email), editor: await mails(w, w.editor.email) }),
	},
	{
		id: 'Reports › empty the log (admin_post_diluxone_users_log_empty)',
		method: 'GET',
		url: () => `${ADMIN_POST}?action=diluxone_users_log_empty`,
		nonce: { field: '_wpnonce', action: 'diluxone_users_log_empty' },
		actor: 'admin',
		lacking: 'editor',
		expect: ADMIN,
		// The subscriber's and the editor's sign-ins of the beforeAll are rows of their own.
		probe: async (w) => {
			const rows = { subscriber: await logRows(w, w.subscriber.email), editor: await logRows(w, w.editor.email) };

			expect(rows.subscriber + rows.editor, 'there are rows to lose').toBeGreaterThan(0);

			return rows;
		},
	},
	{
		id: 'Reports › close all of somebody’s sessions (admin_post_diluxone_users_sessions_admin)',
		method: 'POST',
		url: () => ADMIN_POST,
		fields: (w) => ({ action: 'diluxone_users_sessions_admin', diluxone_users_user: String(w.victim.id) }),
		nonce: { field: '_wpnonce', action: 'diluxone_users_sessions_admin' },
		actor: 'admin',
		lacking: 'editor',
		expect: ADMIN,
		probe: victimState,
	},
	{
		id: 'Create the page (admin_post_diluxone_users_create_page)',
		method: 'GET',
		url: () => `${ADMIN_POST}?action=diluxone_users_create_page&page=diluxone_users_register_page`,
		nonce: { field: '_wpnonce', action: 'diluxone_users_create_page' },
		actor: 'admin',
		lacking: 'editor',
		expect: ADMIN,
		// No page chosen: a create that got through would make one and choose it.
		prepare: async (options) => options.set({ diluxone_users_register_page: 0 }),
		probe: async (w) => ({
			chosen: await w.site.getOptions(['diluxone_users_register_page']),
			pages: devWp(['post', 'list', '--post_type=page', '--format=count']),
		}),
	},
	{
		id: 'the preview’s "Try" token (admin_post_diluxone_users_preview_try)',
		method: 'GET',
		url: () => `${ADMIN_POST}?action=diluxone_users_preview_try&screen=diluxone-users-design&panel=wp`,
		nonce: { field: 'diluxone_users_try_nonce', action: 'diluxone_users_preview_try' },
		actor: 'admin',
		lacking: 'editor',
		expect: ADMIN,
		probe: async () => null,
		leaks: (answer) => expect(answer.location, 'no trial token handed out').not.toContain('diluxone-users-try'),
	},
	{
		id: 'the live preview (wp_ajax_diluxone_users_preview)',
		method: 'POST',
		url: () => ADMIN_AJAX,
		fields: () => ({ action: 'diluxone_users_preview', screen: 'diluxone-users-design', panel: 'social' }),
		nonce: { field: 'nonce', action: 'diluxone_users_preview' },
		actor: 'admin',
		lacking: 'editor',
		expect: { 'no nonce': 'nonce', 'forged nonce': 'nonce', 'without the right': 'forbidden', 'signed out': 'bad' },
		probe: async () => null,
		leaks: (answer) => expect(answer.body, 'no drawing handed out').not.toContain('"success":true'),
	},
	{
		id: 'the preview’s theme colours (?diluxone-users-vars)',
		method: 'GET',
		url: () => '/?diluxone-users-vars=1',
		nonce: { field: '_wpnonce', action: 'diluxone_users_preview' },
		actor: 'admin',
		lacking: 'editor',
		expect: { 'no nonce': 'forbidden', 'forged nonce': 'forbidden', 'without the right': 'forbidden', 'signed out': 'forbidden' },
		probe: async () => null,
		leaks: (answer) => expect(answer.type, 'no stylesheet handed out').not.toContain('text/css'),
	},

	/* ── The forms the screens post to admin_init ──────────────────── */
	{
		id: 'Account › a section switched off (section action, admin_init)',
		method: 'GET',
		url: () => '/wp-admin/admin.php?page=diluxone-users-account&tab=sections&diluxone_users_action=off&section=details',
		nonce: { field: '_wpnonce', action: 'diluxone_users_section_action' },
		actor: 'admin',
		lacking: 'editor',
		expect: SCREEN,
		probe: async (w) => w.site.getOptions(['diluxone_users_account_sections']),
	},
	{
		id: 'Account › a section deleted (section action, admin_init)',
		method: 'GET',
		url: () => '/wp-admin/admin.php?page=diluxone-users-account&tab=sections&diluxone_users_action=delete&section=home',
		nonce: { field: '_wpnonce', action: 'diluxone_users_section_action' },
		actor: 'admin',
		lacking: 'editor',
		expect: SCREEN,
		probe: async (w) => w.site.getOptions(['diluxone_users_account_sections']),
	},
	{
		id: 'Account › the order of the sections (order form, admin_init)',
		method: 'POST',
		url: () => '/wp-admin/admin.php?page=diluxone-users-account&tab=sections',
		fields: () => ({ 'diluxone_users_order_form[]': ['privacy', 'security', 'details', 'home'] }),
		nonce: { field: 'diluxone_users_order_form_nonce', action: 'diluxone_users_order_form' },
		actor: 'admin',
		lacking: 'editor',
		// Which form was sent is told by which nonce field is there: with
		// none, no branch runs and the screen is drawn.
		expect: { ...SCREEN, 'no nonce': 'ignored' },
		probe: async (w) => w.site.getOptions(['diluxone_users_account_sections']),
	},
	{
		id: 'Account › a section’s detail (section form, admin_init)',
		method: 'POST',
		url: () => '/wp-admin/admin.php?page=diluxone-users-account&tab=sections',
		fields: () => ({
			'diluxone_users_section_form[id]': 'home',
			'diluxone_users_section_form[label]': 'Hacked',
			'diluxone_users_section_form[slug]': 'hacked',
		}),
		nonce: { field: 'diluxone_users_section_form_nonce', action: 'diluxone_users_section_form' },
		actor: 'admin',
		lacking: 'editor',
		expect: { ...SCREEN, 'no nonce': 'ignored' },
		probe: async (w) => w.site.getOptions(['diluxone_users_account_sections']),
	},
	{
		id: 'Account › "Your data" switches (privacy form, admin_init)',
		method: 'POST',
		url: () => '/wp-admin/admin.php?page=diluxone-users-account&tab=sections',
		// Both boxes left out: a save that went through would switch both off.
		fields: () => ({ diluxone_users_privacy_export_when: 'admin' }),
		nonce: { field: 'diluxone_users_privacy_nonce', action: 'diluxone_users_privacy' },
		actor: 'admin',
		lacking: 'editor',
		expect: { ...SCREEN, 'no nonce': 'ignored' },
		prepare: async (options) => options.set({ diluxone_users_privacy_export: 1, diluxone_users_privacy_delete: 1 }),
		probe: async (w) =>
			w.site.getOptions(['diluxone_users_privacy_export', 'diluxone_users_privacy_delete', 'diluxone_users_privacy_export_when']),
	},
	{
		id: 'Fields › a field deleted (field action, admin_init)',
		method: 'GET',
		url: () => '/wp-admin/admin.php?page=diluxone-users-fields&diluxone_users_action=delete&field=e2e_refusal',
		nonce: { field: '_wpnonce', action: 'diluxone_users_field_action' },
		actor: 'admin',
		lacking: 'editor',
		expect: SCREEN,
		prepare: async (options) => options.set({ diluxone_users_fields: FIELDS }),
		probe: async (w) => w.site.getOptions(['diluxone_users_fields']),
	},
	{
		id: 'Fields › a field moved (field action, admin_init)',
		method: 'GET',
		url: () => '/wp-admin/admin.php?page=diluxone-users-fields&diluxone_users_action=up&field=e2e_refusal',
		nonce: { field: '_wpnonce', action: 'diluxone_users_field_action' },
		actor: 'admin',
		lacking: 'editor',
		expect: SCREEN,
		prepare: async (options) => options.set({ diluxone_users_fields: FIELDS }),
		probe: async (w) => w.site.getOptions(['diluxone_users_fields']),
	},
	{
		id: 'Fields › a field saved (field form, admin_init)',
		method: 'POST',
		url: () => '/wp-admin/admin.php?page=diluxone-users-fields&field=new',
		fields: () => ({
			'diluxone_users_field[label]': 'Hacked',
			'diluxone_users_field[key]': 'e2e_hacked',
			'diluxone_users_field[type]': 'text',
		}),
		nonce: { field: 'diluxone_users_field_nonce', action: 'diluxone_users_field' },
		actor: 'admin',
		lacking: 'editor',
		// The nonce is asked with wp_verify_nonce() and a wrong one is simply
		// not this form: the screen is drawn and nothing is saved.
		expect: { ...SCREEN, 'no nonce': 'ignored', 'forged nonce': 'ignored' },
		prepare: async (options) => options.set({ diluxone_users_fields: FIELDS }),
		probe: async (w) => w.site.getOptions(['diluxone_users_fields']),
	},
	{
		id: 'Fields › suggested fields added (suggested form, admin_init)',
		method: 'POST',
		url: () => '/wp-admin/admin.php?page=diluxone-users-fields&tab=suggested',
		fields: () => ({ 'diluxone_users_suggested[]': ['diluxone_users_phone', 'diluxone_users_country'] }),
		nonce: { field: 'diluxone_users_suggested_nonce', action: 'diluxone_users_suggested' },
		actor: 'admin',
		lacking: 'editor',
		expect: { ...SCREEN, 'no nonce': 'ignored', 'forged nonce': 'ignored' },
		prepare: async (options) => options.set({ diluxone_users_fields: FIELDS }),
		probe: async (w) => w.site.getOptions(['diluxone_users_fields']),
	},
	{
		id: 'Social › a provider forgotten (admin_action_diluxone_users_social_toggle)',
		method: 'GET',
		url: () => '/wp-admin/admin.php?action=diluxone_users_social_toggle&red=mock&diluxone_users_action=forget',
		nonce: { field: '_wpnonce', action: 'diluxone_users_social_toggle' },
		actor: 'admin',
		lacking: 'editor',
		expect: SCREEN,
		prepare: async (options) =>
			options.set({ diluxone_e2e_sso: 1, diluxone_users_sso: { mock: { active: 1, id: 'e2e-client', secret: 'e2e-secret', tested: 1 } } }),
		probe: async (w) => w.site.getOptions(['diluxone_users_sso']),
	},
	{
		id: 'Social › a provider switched off (admin_action_diluxone_users_social_toggle)',
		method: 'GET',
		url: () => '/wp-admin/admin.php?action=diluxone_users_social_toggle&red=mock&diluxone_users_action=off',
		nonce: { field: '_wpnonce', action: 'diluxone_users_social_toggle' },
		actor: 'admin',
		lacking: 'editor',
		expect: SCREEN,
		prepare: async (options) =>
			options.set({ diluxone_e2e_sso: 1, diluxone_users_sso: { mock: { active: 1, id: 'e2e-client', secret: 'e2e-secret', tested: 1 } } }),
		probe: async (w) => w.site.getOptions(['diluxone_users_sso']),
	},
	{
		id: 'Social › a provider’s credentials (provider form)',
		method: 'POST',
		url: () => '/wp-admin/admin.php?page=diluxone-users-social&provider=mock&tab=settings',
		fields: () => ({ diluxone_users_client_id: 'hacked-id', diluxone_users_client_secret: 'hacked-secret', diluxone_users_active: '1' }),
		nonce: { field: 'diluxone_users_provider_nonce', action: 'diluxone_users_provider' },
		actor: 'admin',
		lacking: 'editor',
		expect: { ...SCREEN, 'no nonce': 'ignored', 'forged nonce': 'ignored' },
		prepare: async (options) =>
			options.set({ diluxone_e2e_sso: 1, diluxone_users_sso: { mock: { active: 0, id: 'e2e-client', secret: 'e2e-secret' } } }),
		probe: async (w) => w.site.getOptions(['diluxone_users_sso']),
		leaks: (answer) => expect(answer.body, 'the stored secret is never printed').not.toContain('e2e-secret'),
	},
	{
		id: 'Users › Edit user: the plugin’s block, a second factor removed (user-edit.php)',
		method: 'POST',
		url: () => '/wp-admin/user-edit.php',
		fields: (w) => ({
			action: 'update',
			user_id: String(w.victim.id),
			email: w.victim.email,
			nickname: 'victim',
			diluxone_users_forget_totp: '1',
			'diluxone_users_forget_passkey[]': 'e2e-victim-key',
		}),
		nonce: { field: '_wpnonce', action: (w) => `update-user_${w.victim.id}` },
		actor: 'admin',
		lacking: 'editor',
		// (c) is WordPress's own refusal, before the plugin's hook fires:
		// user-edit.php stops with wp_die() and no status, which is a 500.
		expect: { 'no nonce': 'nonce', 'forged nonce': 'nonce', 'without the right': { status: 500 }, 'signed out': 'to-login' },
		probe: victimState,
	},
];

/** The request context of each person a row names. */
function people(w: World): (who: string) => APIRequestContext {
	return (who) => (who === 'guest' ? w.guest : (w as unknown as Record<string, Person>)[who].api);
}

test.describe('The side door the refusals lean on', () => {
	/**
	 * Everything in (c) rests on the nonce the mu-plugin makes being the one
	 * that person's own page would carry. If it were not, every "without the
	 * right" refusal below would be a nonce refusal wearing a disguise. So the
	 * same route's nonce is first shown to get a real form through.
	 */
	test('a nonce made for the subscriber gets the subscriber’s own form through', async () => {
		const { user, nonce } = await nonceFor(world.subscriber.api, 'diluxone_users_notifications');

		expect(user, 'the nonce is made for the session’s owner').toBe(world.subscriber.id);

		const answer = await send(world.subscriber.api, 'POST', ADMIN_POST, {
			action: 'diluxone_users_notifications',
			_wpnonce: nonce,
			diluxone_users_notify_login: '1',
			diluxone_users_notify_security: '1',
		});

		expectRefused(answer, { state: 'saved' }, 'the subscriber’s own notifications form');

		// Both ticked: what the policy gives anyway, so every probe below
		// starts from the same answer whichever order the tests run in.
		const meta = await metaOf(world.door, world.subscriber.email, ['diluxone_users_notify_login']);

		expect(meta.diluxone_users_notify_login).toBe('1');
	});

	test('a nonce made for the editor is the editor’s, and a stranger’s is nobody’s', async () => {
		expect((await nonceFor(world.editor.api, 'x')).user).toBe(world.editor.id);
		expect((await nonceFor(world.guest, 'x')).user).toBe(0);
	});
});

test.describe('Every handler that writes refuses what it should', () => {
	for (const row of ROWS) {
		for (const kind of kindsOf(row)) {
			const outcome = row.expect[kind] as Outcome;

			test(`${row.id} › ${kind}`, async ({ options }) => {
				await row.prepare?.(options, world);

				const before = await row.probe(world);
				const answer = await fire(world, row, kind, people(world));

				expectRefused(answer, outcome, `${row.id} (${kind})`);
				row.leaks?.(answer);

				expect(await row.probe(world), `${row.id} (${kind}) wrote something`).toEqual(before);
			});
		}
	}
});

/* ── Somebody else's data request ──────────────────────────────────── */

test.describe('A data request is its owner’s alone', () => {
	/** A request filed for the victim, with its key, made the way WordPress makes one. */
	function requestFor(email: string, owner: number, kind: 'export_personal_data' | 'remove_personal_data'): { id: number; key: string } {
		// Carrying the plugin's own mark, as one asked for from the account
		// does: without it the closing form does not know the request at all.
		const mark = kind === 'remove_personal_data' ? 'diluxone_users_close' : 'diluxone_users_export';
		const out = devWp([
			'eval',
			`$id = wp_create_user_request( '${email}', '${kind}', array( '${mark}' => ${owner} ) ); echo $id . ' ' . wp_generate_user_request_key( $id );`,
		]);
		const [id, key] = out.split(/\s+/).slice(-2);

		return { id: Number(id), key };
	}

	test('the download of another account’s export is refused, with the subscriber’s own valid link', async () => {
		const request = requestFor(world.victim.email, world.victim.id, 'export_personal_data');

		try {
			const { nonce } = await nonceFor(world.subscriber.api, 'diluxone_users_data_download');
			const answer = await send(world.subscriber.api, 'GET', `${ADMIN_POST}?action=diluxone_users_data_download&request=${request.id}&_wpnonce=${nonce}`);

			expectRefused(answer, 'forbidden', 'another account’s export');
			expect(answer.type, 'a refusal is not a file').not.toContain('zip');
		} finally {
			devWp(['post', 'delete', String(request.id), '--force']);
		}
	});

	test('"Yes, delete my account" with another account’s request and key closes nobody', async () => {
		const request = requestFor(world.victim.email, world.victim.id, 'remove_personal_data');

		try {
			const { nonce } = await nonceFor(world.subscriber.api, 'diluxone_users_confirm_close');
			const answer = await send(world.subscriber.api, 'POST', ADMIN_POST, {
				action: 'diluxone_users_confirm_close',
				_wpnonce: nonce,
				diluxone_users_request_id: String(request.id),
				diluxone_users_key: request.key,
			});

			expectRefused(answer, { state: 'other' }, 'another account’s closing');
			expect((await world.site.user(world.victim.email)).exists, 'the victim’s account').toBe(true);
			expect((await world.site.user(world.subscriber.email)).exists, 'the sender’s account').toBe(true);
			expect(devWp(['post', 'get', String(request.id), '--field=post_status']), 'the request is still waiting').not.toBe('request-completed');
		} finally {
			devWp(['post', 'delete', String(request.id), '--force']);
		}
	});
});

/* ── The settings panels ───────────────────────────────────────────── */

/**
 * Every panel registered with `diluxone_users_register_panel()` that saves.
 *
 * Each row names the tab, the form (the panel form, or the arrangement's own),
 * one control changed the way a person changes it, and the options that
 * control writes.
 */
interface PanelRow {
	screen: string;
	tab: string;
	form?: string;
	change: Record<string, string>;
	options: string[];
	prepare?: (options: Options) => Promise<void>;
}

const PANEL_FORM = '#diluxone-users-panel-form';

const PANELS: PanelRow[] = [
	{ screen: 'diluxone-users-login', tab: 'page', change: { diluxone_users_wp_screens: 'wp' }, options: ['diluxone_users_wp_screens'] },
	{ screen: 'diluxone-users-login', tab: 'ways', change: { diluxone_users_login_expiry: '37' }, options: ['diluxone_users_login_expiry', 'diluxone_users_login_method'] },
	{
		screen: 'diluxone-users-login',
		tab: 'arrangement',
		form: '#diluxone-users-arrangement',
		change: { diluxone_users_login_layout: 'tabs' },
		options: ['diluxone_users_login_layout', 'diluxone_users_login_order'],
	},
	{ screen: 'diluxone-users-login', tab: 'register', change: { diluxone_users_register_form: '1' }, options: ['diluxone_users_register_form', 'diluxone_users_login_register'] },
	{ screen: 'diluxone-users-login', tab: 'messages', change: { 'diluxone_users_message[login_error]': 'Hacked' }, options: ['diluxone_users_login_messages'] },
	{ screen: 'diluxone-users-security', tab: '2fa', change: { diluxone_users_2fa_mode: 'off' }, options: ['diluxone_users_2fa_mode', 'diluxone_users_2fa_methods'] },
	{ screen: 'diluxone-users-security', tab: 'passkeys', change: { diluxone_users_passkey_where: 'device' }, options: ['diluxone_users_passkey_where', 'diluxone_users_passkey_verify'] },
	{ screen: 'diluxone-users-security', tab: 'sessions', change: { diluxone_users_session_long_days: '99' }, options: ['diluxone_users_session_long_days', 'diluxone_users_sessions_show'] },
	{ screen: 'diluxone-users-security', tab: 'proxy', change: { diluxone_users_trusted_proxies: '10.9.9.9' }, options: ['diluxone_users_trusted_proxies', 'diluxone_users_ip_header'] },
	{ screen: 'diluxone-users-reports', tab: 'logging', change: { diluxone_users_log_days: '7' }, options: ['diluxone_users_log_days', 'diluxone_users_log_levels'] },
	{ screen: 'diluxone-users-design', tab: 'brand', change: { diluxone_users_button_style: 'outline' }, options: ['diluxone_users_button_style', 'diluxone_users_style_accent'] },
	{ screen: 'diluxone-users-design', tab: 'login', change: { diluxone_users_login_title: 'Hacked' }, options: ['diluxone_users_login_title', 'diluxone_users_login_template'] },
	{ screen: 'diluxone-users-design', tab: 'register', change: { diluxone_users_register_title: 'Hacked' }, options: ['diluxone_users_register_title'] },
	{ screen: 'diluxone-users-design', tab: 'account', change: { diluxone_users_account_layout: 'side' }, options: ['diluxone_users_account_layout', 'diluxone_users_account_template'] },
	{ screen: 'diluxone-users-design', tab: 'social', change: { diluxone_users_sso_button_text: 'Hacked' }, options: ['diluxone_users_sso_button_text', 'diluxone_users_sso_button_skin'] },
	{ screen: 'diluxone-users-design', tab: 'photo', change: { diluxone_users_avatar_max_kb: '11' }, options: ['diluxone_users_avatar_max_kb', 'diluxone_users_avatar_upload'] },
	{ screen: 'diluxone-users-design', tab: 'wp', change: { diluxone_users_wp_login_brand: '1' }, options: ['diluxone_users_wp_login_brand'] },
	{ screen: 'diluxone-users-account', tab: 'page', change: { diluxone_users_account_page: '0' }, options: ['diluxone_users_account_page'] },
	{ screen: 'diluxone-users-account', tab: 'handle', change: { diluxone_users_handle_reserved: 'hacked' }, options: ['diluxone_users_handle_reserved', 'diluxone_users_handle_enabled'] },
	{ screen: 'diluxone-users-account', tab: 'menu', change: { diluxone_users_menu_style: 'name' }, options: ['diluxone_users_menu_style', 'diluxone_users_menu_location'] },
	{ screen: 'diluxone-users-account', tab: 'dashboard', change: { diluxone_users_wp_profile: 'block' }, options: ['diluxone_users_wp_profile', 'diluxone_users_admin_bar'] },
	{ screen: 'diluxone-users-social', tab: 'general', change: { diluxone_users_sso_verified_only: '1' }, options: ['diluxone_users_sso_verified_only', 'diluxone_users_sso_link_by_email'] },
	{
		screen: 'diluxone-users-notices',
		tab: 'rules',
		change: { 'diluxone_users_notice_rules[diluxone_users_notify_login]': 'off' },
		options: ['diluxone_users_notice_rules'],
	},
	{
		screen: 'diluxone-users-notices',
		tab: 'templates',
		change: { 'diluxone_users_mail[login_link][subject]': 'Hacked {site}' },
		options: ['diluxone_users_mail_templates'],
	},
];

/**
 * Sends a panel's real form from the administrator's browser, with its nonce
 * tampered: removed, or replaced with one WordPress never made.
 *
 * `HTMLFormElement.submit()` and not the button: no script on the page gets a
 * say, so what reaches the server is exactly the tampered form.
 */
async function tamperedSave(page: Page, form: string, change: Record<string, string>, nonce: string | null): Promise<number> {
	await formFields(page, form, change);

	let status = 0;

	page.once('response', (response) => {
		if (response.request().method() === 'POST' && response.request().resourceType() === 'document') {
			status = response.status();
		}
	});

	await navigated(page, () =>
		page
			.locator(form)
			.first()
			.evaluate((element: HTMLFormElement, nonce: string | null) => {
				const field = element.querySelector<HTMLInputElement>('input[name="diluxone_users_panel_nonce"]');

				if (nonce === null) {
					field?.remove();
				} else if (field) {
					field.value = nonce;
				}

				HTMLFormElement.prototype.submit.call(element);
			}, nonce)
	);

	return status;
}

test.describe('Every settings panel refuses a save it should not take', () => {
	for (const row of PANELS) {
		const name = `${row.screen} › ${row.tab}`;
		const url = `/wp-admin/admin.php?page=${row.screen}&tab=${row.tab}`;
		const form = row.form ?? PANEL_FORM;

		for (const [kind, nonce] of [
			['no nonce', null],
			['forged nonce', FORGED],
		] as const) {
			test(`${name} › ${kind}, the real form tampered in the browser`, async ({ page, site }) => {
				await page.goto(url);
				await expect(page.locator(form).first(), 'the panel draws its form').toBeAttached();

				const before = await site.getOptions(row.options);
				const status = await tamperedSave(page, form, row.change, nonce);

				expect(status, `${name} (${kind})`).toBe(200);
				await expect(adminSaved(page), `${name} (${kind}): congratulated itself`).toHaveCount(0);
				expect(await site.getOptions(row.options), `${name} (${kind}) wrote something`).toEqual(before);
			});
		}

		test(`${name} › without the right (an editor, with a valid nonce of their own)`, async ({ page, site }) => {
			await page.goto(url);

			const fields = await formFields(page, form, row.change);
			const before = await site.getOptions(row.options);

			fields.diluxone_users_panel_nonce = [(await nonceFor(world.editor.api, `diluxone_users_panel_${row.screen}`)).nonce];

			const answer = await send(world.editor.api, 'POST', url, fields);

			expectRefused(answer, 'forbidden', `${name} (editor)`);
			expect(await site.getOptions(row.options), `${name} (editor) wrote something`).toEqual(before);
		});

		test(`${name} › signed out`, async ({ page, site }) => {
			await page.goto(url);

			const fields = await formFields(page, form, row.change);
			const before = await site.getOptions(row.options);

			fields.diluxone_users_panel_nonce = [(await nonceFor(world.guest, `diluxone_users_panel_${row.screen}`)).nonce];

			const answer = await send(world.guest, 'POST', url, fields);

			expectRefused(answer, 'to-login', `${name} (signed out)`);
			expect(await site.getOptions(row.options), `${name} (signed out) wrote something`).toEqual(before);
		});

		test(`${name} › a subscriber, with a valid nonce of their own`, async ({ page, site }) => {
			await page.goto(url);

			const fields = await formFields(page, form, row.change);
			const before = await site.getOptions(row.options);

			fields.diluxone_users_panel_nonce = [(await nonceFor(world.subscriber.api, `diluxone_users_panel_${row.screen}`)).nonce];

			const answer = await send(world.subscriber.api, 'POST', url, fields);

			expectRefused(answer, 'forbidden', `${name} (subscriber)`);
			expect(await site.getOptions(row.options), `${name} (subscriber) wrote something`).toEqual(before);
		});
	}
});

/* ── The network's commands, on a single site ──────────────────────── */

test.describe('The network’s WP-CLI commands refuse a single site', () => {
	for (const command of [
		['diluxone-users', 'network', 'migrate'],
		['diluxone-users', 'network', 'membership', 'sync'],
	]) {
		test(`wp ${command.join(' ')} › errors, and writes nothing`, async ({ site }) => {
			const before = await site.getOptions(['diluxone_users_network_version', 'diluxone_users_membership']);

			expect(() => devWp(command), 'refused: this is not a network').toThrow(/not a network/i);
			expect(await site.getOptions(['diluxone_users_network_version', 'diluxone_users_membership'])).toEqual(before);
		});
	}
});
