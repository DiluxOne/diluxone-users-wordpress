import { Browser } from '@playwright/test';
import { test, expect, whoOn, toTheHub, SiteHandle } from './support';
import { freshEmail, linkIn, waitForMail } from '../support/api';
import { askForLink, savePanel, signInWithPassword } from '../support/ui';
import { NETWORK_ADMIN_STATE } from '../../../playwright.network.config';

/**
 * The hub's E-mail notices on a network: saved on the main site's screen, and
 * what a person sent from /alpha/ meets — the hub's rules and wording in the
 * mail.
 */

test.use({ storageState: NETWORK_ADMIN_STATE });

const PASSWORD = 'e2e-Network-Notices-1!';

/** A browser that is another device, signing in from a site's door. */
async function signInOn(browser: Browser, one: SiteHandle, hub: SiteHandle, email: string, agent: string) {
	const context = await browser.newContext({ userAgent: agent, storageState: { cookies: [], origins: [] } });
	const page = await context.newPage();
	const back = await toTheHub(page, one, hub);

	await signInWithPassword(page, email, PASSWORD);
	await page.waitForURL((url) => url.href.startsWith(back), { waitUntil: 'domcontentloaded' });
	expect(await whoOn(page, hub.url)).toBe(email);

	return context;
}

test('the hub’s e-mail rules and wording govern the mail of somebody who comes in through /alpha/', async ({ page, guest, browser, hub, alpha }) => {
	await hub.keep(['diluxone_users_notice_rules', 'diluxone_users_mail_templates']);
	await hub.set({ diluxone_users_notice_rules: {}, diluxone_users_mail_templates: {} });

	await page.goto(`${hub.url}wp-admin/admin.php?page=diluxone-users-notices&tab=rules`);
	await page.locator('select[name="diluxone_users_notice_rules[diluxone_users_notify_login]"]').selectOption('never');
	await savePanel(page);

	await page.goto(`${hub.url}wp-admin/admin.php?page=diluxone-users-notices&tab=templates`);

	const fold = page.locator('details:has(#diluxone_users_mail_login_link_subject)');

	await fold.locator('> summary').click();
	await page.locator('#diluxone_users_mail_login_link_subject').fill('E2E hub link for {site}');
	await savePanel(page);

	const email = freshEmail('net-notice');

	await alpha.site.makeUser({ email, password: PASSWORD });

	// The link, asked for through /alpha/'s door: the hub sends it, in the hub's words.
	await toTheHub(guest, alpha, hub);

	const asked = Date.now() / 1000;

	await askForLink(guest, guest.url(), email);

	const mail = await waitForMail(hub.site, email, { after: asked - 1, subject: /^E2E hub link for / });

	expect(mail.subject).not.toContain('{site}');
	await guest.goto(linkIn(mail));
	expect(await whoOn(guest, hub.url), 'and the link in it signs in').toBe(email);

	// Two more devices from /alpha/'s door: the hub's "never" means no mail about them.
	const first = await signInOn(browser, alpha, hub, email, 'E2E-Net-One Chrome/1');
	const second = await signInOn(browser, alpha, hub, email, 'E2E-Net-Two Firefox/2');

	await first.close();
	await second.close();

	for (const box of [hub.site, alpha.site]) {
		const others = (await box.mail(email)).filter((one) => !one.body.includes('diluxone_users_token'));

		expect(others, 'no new-device mail anywhere').toEqual([]);
	}
});
