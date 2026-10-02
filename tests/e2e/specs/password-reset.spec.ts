import { test, expect, expectSignedIn, expectSignedOut } from '../support/fixtures';
import { freshEmail, linkIn, waitForMail } from '../support/api';
import { navigated, notice, resetScreen, signInWithPassword } from '../support/ui';

/**
 * "I forgot my password", and the three places it can end.
 *
 * The setting has three answers and each one puts the person somewhere else,
 * which is the only thing worth testing here: `wp` leaves them on WordPress's
 * grey box, `site` brings them back to the site's own page to type the new
 * one, and `link` says nobody resets anything because the e-mail link is the
 * way back in.
 *
 * `site` is the one that needs a browser to prove: the key arrives in the
 * address, is moved into a cookie, and the address is cleaned — three
 * redirects and a cookie, none of which a unit test can see.
 *
 * Covers T-RST-04 and the reachable half of T-RST-02.
 */

const OLD_PASSWORD = 'e2e-Old-Password-1!';
const NEW_PASSWORD = 'e2e-New-Password-2!';

/** Goes through WordPress's own "lost password" form and returns its e-mail link. */
async function askWordPressForAReset(page: any, site: any, email: string): Promise<string> {
	await page.goto('/wp-login.php?action=lostpassword');
	await page.locator('input[name="user_login"]').fill(email);
	await page.locator('#wp-submit').click();

	const mail = await waitForMail(site, email, { subject: /contrase|password/i });

	return linkIn(mail);
}

test.describe('Choosing a new password', () => {
	test('“wp”: the link lands on WordPress’s own screen and the new password works', async ({
		page,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_lost_password: 'wp', diluxone_users_login_method: 'both' });

		const email = freshEmail('rstwp');
		await site.makeUser({ email, password: OLD_PASSWORD });

		const link = await askWordPressForAReset(page, site, email);

		await page.goto(link);

		// Left where WordPress put it: this is the answer that changes nothing.
		expect(new URL(page.url()).pathname).toContain('wp-login.php');
		await expect(page.locator('#resetpassform, form[name="resetpassform"]')).toBeVisible();

		const pass1 = page.locator('#pass1');

		// WordPress's own script puts a generated password into this field once
		// it loads, and it loads after the markup does. Typing first and letting
		// it land afterwards is how this test passed on its own and failed in a
		// full run: the password that got saved was the generated one, the
		// screen said "password reset" either way, and only the sign-in
		// underneath knew the difference. So: wait for the script to have had
		// its say, then type over it.
		await expect(pass1).not.toHaveValue('');
		await pass1.fill(NEW_PASSWORD);

		// And `pass2` by hand, because the field is hidden and the same script
		// is what normally copies `pass1` across.
		await page.locator('#pass2').evaluate((field: HTMLInputElement, value) => {
			field.value = value;
		}, NEW_PASSWORD);

		await expect(pass1, 'nothing may have typed over it').toHaveValue(NEW_PASSWORD);

		// WordPress answers this one in place rather than with a redirect, so
		// what says it worked is the form being gone.
		await navigated(page, () => page.locator('#wp-submit').click());
		await expect(page.locator('#resetpassform')).toHaveCount(0);

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, NEW_PASSWORD);
		await expectSignedIn(page, email);
	});

	test('“site”: the link lands on the site’s own page and the password is typed there', async ({
		page,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_lost_password: 'site', diluxone_users_login_method: 'both' });

		const email = freshEmail('rstsite');
		await site.makeUser({ email, password: OLD_PASSWORD });

		const link = await askWordPressForAReset(page, site, email);

		await page.goto(link);

		// wp-login.php handed it on to the sign-in page, and the page put the
		// key into a cookie and took it out of the address on the way.
		expect(page.url()).toContain(pages.login.url);
		expect(page.url(), 'the key may not be left in the address bar').not.toContain('diluxone_users_key');

		const screen = resetScreen(page);
		await expect(screen).toBeVisible();
		// It says whose account it is: the key already told the site.
		await expect(screen).toContainText(email);

		// Two that do not match get a message and no change.
		await page.locator('input[name="diluxone_users_pass"]').fill(NEW_PASSWORD);
		await page.locator('input[name="diluxone_users_pass2"]').fill(`${NEW_PASSWORD}-nope`);
		await Promise.all([
			page.waitForURL(/diluxone-users=nomatch/),
			page.locator('form.diluxone-users-form button[type="submit"]').click(),
		]);

		await expect(notice(page, 'error')).toBeVisible();
		// Still on the reset screen: the cookie survived the round trip, so the
		// person can simply try again.
		await expect(resetScreen(page)).toBeVisible();

		await page.locator('input[name="diluxone_users_pass"]').fill(NEW_PASSWORD);
		await page.locator('input[name="diluxone_users_pass2"]').fill(NEW_PASSWORD);
		await Promise.all([
			page.waitForURL(/diluxone-users=changed/),
			page.locator('form.diluxone-users-form button[type="submit"]').click(),
		]);

		// Back on the sign-in form, told it worked, and the new password works.
		await expect(notice(page, 'ok')).toBeVisible();
		await signInWithPassword(page, email, NEW_PASSWORD);
		await expectSignedIn(page, email);
	});

	test('“site”: the same key cannot be used a second time', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_lost_password: 'site', diluxone_users_login_method: 'both' });

		const email = freshEmail('rstonce');
		await site.makeUser({ email, password: OLD_PASSWORD });

		const link = await askWordPressForAReset(page, site, email);

		await page.goto(link);
		await page.locator('input[name="diluxone_users_pass"]').fill(NEW_PASSWORD);
		await page.locator('input[name="diluxone_users_pass2"]').fill(NEW_PASSWORD);
		await Promise.all([
			page.waitForURL(/diluxone-users=changed/),
			page.locator('form.diluxone-users-form button[type="submit"]').click(),
		]);

		// Opening the same address again: the key was spent when the password
		// changed, so there is nothing to type into.
		await page.goto(link);

		await expect(resetScreen(page)).toHaveCount(0);
		await expectSignedOut(page);
	});

	test('“link”: asking for a reset leads to the sign-in page instead', async ({ page, site, pages, options }) => {
		await options.set({
			diluxone_users_lost_password: 'link',
			diluxone_users_login_method: 'link',
			diluxone_users_wp_screens: 'auto',
		});

		const email = freshEmail('rstlink');
		await site.makeUser({ email, password: OLD_PASSWORD });

		await page.goto('/wp-login.php?action=lostpassword');

		// Nowhere: the answer is "get in with the link, the password stays as
		// it was", so the screen that asks for a reset is taken over too.
		expect(page.url()).toContain(pages.login.url);
		await expect(page.locator('input[name="diluxone_users_email"]')).toBeVisible();

		expect(await site.mail(email), 'nothing may have been sent').toEqual([]);
	});

	test('the sign-in page offers the way to a reset only while a password opens something', async ({
		page,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_login_method: 'both' });
		await page.goto(pages.login.url);
		await expect(page.locator('a[href*="action=lostpassword"]')).toBeVisible();

		await options.set({ diluxone_users_login_method: 'link' });
		await page.goto(pages.login.url);
		// No password on this site, so nothing to forget.
		await expect(page.locator('a[href*="action=lostpassword"]')).toHaveCount(0);
	});
});
