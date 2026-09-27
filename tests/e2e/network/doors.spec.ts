import { test, expect, whoOn } from './support';
import { freshEmail, linkIn, waitForMail } from '../support/api';
import { askForLink, emailField, linkForm, registerForm, signInWithPassword, ssoButton, submitPluginForm } from '../support/ui';

/**
 * The ways in, on two sites of one network.
 *
 * On a network the account belongs to the network and the membership to each
 * site. That splits every door in two questions the single-site suite never
 * has to ask: does this door open a session on THIS site, and does walking up
 * to it make somebody a member here. The second one has a strict answer —
 * asking for a link is not having used it, so nobody becomes a member of a
 * site by typing their address into its form — and the network's own
 * registration setting sits above every site's.
 */

const PASSWORD = 'e2e-Network-1!';

const MOCK = { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } };

/** The fields the registration form asks for, pinned — see register.spec.ts for why. */
const PLAIN_FIELDS = [
	{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
];

test.describe('Each site of the network has its own door', () => {
	test('every site draws its sign-in page, and the form posts to that site', async ({ page, alpha, beta }) => {
		for (const one of [alpha, beta]) {
			await page.goto(one.pages.login.url);
			await expect(emailField(page)).toBeVisible();
			// admin-post.php of THIS site: the answer has to come back here,
			// with this site's settings, and not to the main site's.
			await expect(linkForm(page)).toHaveAttribute('action', new RegExp(`/${one.slug}/wp-admin/admin-post\\.php`));
		}
	});

	test('a link asked for on /alpha/ opens a session on /alpha/, with the role /alpha/ gives', async ({
		page,
		alpha,
		beta,
	}) => {
		await alpha.set({ diluxone_users_login_register: 1, diluxone_users_login_role: 'contributor' });
		await beta.set({ diluxone_users_login_role: 'subscriber' });

		const email = freshEmail('net-link');

		await askForLink(page, alpha.pages.login.url, email);

		const link = linkIn(await waitForMail(alpha.site, email));

		expect(link, 'the link points back at the site it was asked on').toContain(alpha.url);

		await page.goto(link);

		expect(new URL(page.url()).pathname, 'and it lands there').toMatch(/^\/alpha\//);
		expect(await whoOn(page, alpha.url)).toBe(email);

		const here = await alpha.site.user(email);

		expect(here.member).toBe(true);
		expect(here.roles, "/alpha/'s role, not /beta/'s").toEqual(['contributor']);
		expect((await beta.site.user(email)).member, 'and a member of /alpha/ only').toBe(false);
	});

	test('a network member who asks on /beta/ is not a member there until they open the link', async ({
		page,
		alpha,
		beta,
	}) => {
		await beta.set({ diluxone_users_login_register: 1, diluxone_users_login_role: 'subscriber' });

		const email = freshEmail('net-join');

		await alpha.site.makeUser({ email, password: PASSWORD });
		expect((await beta.site.user(email)).member, 'starts as a member of /alpha/ only').toBe(false);

		await askForLink(page, beta.pages.login.url, email);

		const link = linkIn(await waitForMail(beta.site, email));

		// Anybody can type anybody's address into a form. If typing it were
		// enough, any address on the network could be made a member of any
		// site by a stranger — and a member is what the site's mail, its
		// reports and its content rules count.
		expect(
			(await beta.site.user(email)).member,
			'asking for a link is not using it: no membership before the click'
		).toBe(false);

		await page.goto(link);

		expect(await whoOn(page, beta.url)).toBe(email);

		const after = await beta.site.user(email);

		expect(after.member, 'opening it is what makes them a member').toBe(true);
		expect(after.roles).toEqual(['subscriber']);
	});

	test('with /beta/ closed to new people, a network member from /alpha/ does not become one', async ({
		page,
		alpha,
		beta,
	}) => {
		await beta.set({ diluxone_users_login_register: 0, diluxone_users_register_form: 0 });

		const email = freshEmail('net-closed');

		await alpha.site.makeUser({ email, password: PASSWORD });
		await askForLink(page, beta.pages.login.url, email);

		const mail = await beta.site.mail(email);

		if (mail.length > 0) {
			await page.goto(linkIn(mail[mail.length - 1]));
		}

		expect((await beta.site.user(email)).member, 'a closed site takes nobody new, from anywhere').toBe(false);
	});
});

test.describe('The network decides whether anybody new can exist at all', () => {
	test.beforeEach(async ({ alpha }) => {
		// Every door on /alpha/ open, so that the only thing saying no is the
		// network.
		await alpha.set({
			diluxone_users_login_register: 1,
			diluxone_users_register_form: 1,
			diluxone_users_fields: PLAIN_FIELDS,
			diluxone_e2e_sso: 1,
			diluxone_users_sso: MOCK,
			diluxone_users_sso_login: 1,
			diluxone_users_sso_register: 1,
			diluxone_users_2fa_mode: 'off',
		});
	});

	test('“Registration is currently turned off” on the network: no door of a site creates anybody', async ({
		browser,
		alpha,
		root,
		network,
	}) => {
		await network.set('registration', 'none');

		// The link.
		const byLink = freshEmail('net-none-link');
		const one = await browser.newPage();

		await askForLink(one, alpha.pages.login.url, byLink);
		expect((await root.user(byLink)).exists, 'the sign-in link made an account').toBe(false);

		// The site's own form.
		const byForm = freshEmail('net-none-form');
		const two = await browser.newPage();

		await two.goto(alpha.pages.register.url);

		if ((await registerForm(two).count()) > 0) {
			await two.locator('input[name="diluxone_users_email"]').fill(byForm);
			await submitPluginForm(two, registerForm(two));
		}

		expect((await root.user(byForm)).exists, 'the registration form made an account').toBe(false);

		// A social network.
		const bySocial = freshEmail('net-none-sso');
		const three = await browser.newPage();

		await alpha.site.setIdentity({ sub: `mock|${bySocial}`, email: bySocial, email_verified: true });
		await three.goto(alpha.pages.login.url);
		await ssoButton(three, 'mock').click();
		await three.waitForLoadState('domcontentloaded');

		expect((await root.user(bySocial)).exists, 'a social sign-in made an account').toBe(false);
	});

	test('“User accounts may be registered” on the network: the site’s own doors work', async ({
		page,
		alpha,
		network,
	}) => {
		await network.set('registration', 'user');

		const email = freshEmail('net-user');

		await askForLink(page, alpha.pages.login.url, email);

		const made = await alpha.site.user(email);

		expect(made.exists).toBe(true);
		expect(made.member).toBe(true);

		await page.goto(linkIn(await waitForMail(alpha.site, email)));
		expect(await whoOn(page, alpha.url)).toBe(email);
	});
});

test.describe('A password is a network password', () => {
	test('a member of /alpha/ signs in on /alpha/ with the password form', async ({ page, alpha }) => {
		const email = freshEmail('net-pass');

		await alpha.site.makeUser({ email, password: PASSWORD });

		await page.goto(alpha.pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await page.waitForLoadState('domcontentloaded');

		expect(await whoOn(page, alpha.url)).toBe(email);
	});
});
