import { test, expect } from '../support/fixtures';
import { freshEmail, waitForMail } from '../support/api';
import { adminUrl, askForLink } from '../support/ui';
import { numbersIn } from '../support/admin-access';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Issue #207, item 6 (C6): Overview's mail line says "Last message sent 7
 * hours ago" while a sign-in link was sent a moment ago through a mailer that
 * does not fire `wp_mail_succeeded` (the e2e mailer keeps the message and says
 * nothing, like the SMTP plugin of the report). The reporter expects the line
 * not to claim that the last message was sent before that one.
 */

test.use({ storageState: ADMIN_STATE });

const HOURS_AGO = 7;

test.describe('Issue 207: Overview › Mail delivery', () => {
	test('after a link was sent through a mailer that says nothing, the line does not claim the last message was hours ago', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.set({
			WPLANG: '',
			diluxone_users_login_method: 'both',
			diluxone_users_login_page: pages.login.id,
			diluxone_users_2fa_mode: 'off',
			diluxone_e2e_mail_outcome: '',
			diluxone_users_mail_last: { ok: 1, time: Math.floor(Date.now() / 1000) - HOURS_AGO * 3600, error: '' },
		});

		const email = freshEmail('repro-207');

		await site.makeUser({ email, password: 'e2e-Repro-207!' });
		await askForLink(guest, pages.login.url, email);
		await waitForMail(site, email);

		await page.goto(adminUrl('diluxone-users', 'doors'));

		// Sign-in page, method, then the mail: the third row of the summary.
		const detail = page.locator('table.diluxone-users-summary tbody tr').nth(2).locator('.diluxone-users-summary__detail');
		const text = await detail.innerText();

		const claimsStaleLastMessage = /last message/i.test(text) && numbersIn(text).includes(HOURS_AGO);

		expect(claimsStaleLastMessage, `the mail line must not say the last message went ${HOURS_AGO} hours ago (it says: "${text}")`).toBe(false);
	});
});
