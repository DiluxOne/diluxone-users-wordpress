import { test, expect, expectSignedIn, expectSignedOut } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { accountSection, openAllPanels, openPanel, signInWithPassword } from '../support/ui';

/**
 * Passkeys, with a key that lives in the browser under the test's control.
 *
 * There is no way to click through a real passkey: the prompt is drawn by the
 * operating system and the signing happens in hardware the page cannot reach.
 * What there is instead is Chrome's virtual authenticator — a key the test
 * creates over the DevTools protocol, which the page then meets through the
 * ordinary `navigator.credentials` API and cannot tell apart from a real one.
 *
 * Two things this needs and gets: a secure context, which `http://localhost`
 * counts as, so no HTTPS is required; and Chromium, which is the only engine
 * of the three that has the protocol. On any other browser the spec skips
 * itself rather than pretending.
 *
 * Covers T-PK-03.
 */

const PASSWORD = 'e2e-Passkey-1!';

/**
 * The key, as Chrome will hand it to the page.
 *
 * `internal` with the verification already done is a phone or a laptop with a
 * fingerprint reader — the case the setting called "require verifying who they
 * are" is about.
 */
const VIRTUAL_KEY = {
	protocol: 'ctap2' as const,
	transport: 'internal' as const,
	hasResidentKey: true,
	hasUserVerification: true,
	isUserVerified: true,
	automaticPresenceSimulation: true,
};

/**
 * Waits for the passkey sign-in to land somewhere, and says why if it did not.
 *
 * The button does its work in JavaScript and then sets `location.href`, so
 * there is nothing to wait for but the address changing. When it fails it
 * changes nothing and writes into the notice box instead — which is the
 * message worth putting in the failure rather than "timeout".
 */
async function waitForPasskeyLanding(page: any, loginUrl: string): Promise<void> {
	try {
		await page.waitForURL((url: URL) => !url.href.startsWith(loginUrl), { timeout: 20_000 });
	} catch (error) {
		const said = await page.locator('[data-diluxone-users-passkey-notice]').innerText();

		throw new Error(`The passkey sign-in went nowhere. The page said: ${said || '(nothing)'}`);
	}
}

test.describe('Signing in with a passkey', () => {
	test.skip(({ browserName }) => browserName !== 'chromium', 'the virtual authenticator is a Chromium protocol');

	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_users_passkey_enabled: 1,
			diluxone_users_passkey_where: 'any',
			diluxone_users_passkey_verify: 1,
			diluxone_users_login_method: 'both',
			diluxone_users_2fa_mode: 'optional',
		});
	});

	test('register one from the account screen, then get in with nothing typed', async ({ page, site, pages }) => {
		const email = freshEmail('passkey');
		await site.makeUser({ email, password: PASSWORD });

		const cdp = await page.context().newCDPSession(page);

		await cdp.send('WebAuthn.enable', { enableUI: false });

		const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', { options: VIRTUAL_KEY });

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expectSignedIn(page, email);

		await page.goto(accountSection(pages.account.url, 'security'));

		const panel = await openPanel(page, '[data-diluxone-users-passkey="register"]');

		await panel.locator('[data-diluxone-users-passkey-label]').fill('The e2e laptop');
		await panel.locator('[data-diluxone-users-passkey="register"]').click();

		// The page reloads itself once the key is stored, and the new key shows
		// up in the list with the name it was given.
		await expect(page.locator('input[name="diluxone_users_passkey_label"]')).toHaveValue('The e2e laptop', {
			timeout: 20_000,
		});

		const credentials = await cdp.send('WebAuthn.getCredentials', { authenticatorId });
		expect(credentials.credentials.length, 'the authenticator holds the key').toBe(1);

		// Out, and back in with the key alone: no address, no password, no code.
		await page.context().clearCookies();
		await page.goto(pages.login.url);

		await page.locator('[data-diluxone-users-passkey="login"]').click();
		await waitForPasskeyLanding(page, pages.login.url);

		await expectSignedIn(page, email);

		await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
	});

	test('a key can be given another name, and the list says it', async ({ page, site, pages }) => {
		const email = freshEmail('passkey-name');
		await site.makeUser({ email, password: PASSWORD });

		const cdp = await page.context().newCDPSession(page);

		await cdp.send('WebAuthn.enable', { enableUI: false });

		const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', { options: VIRTUAL_KEY });

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await page.goto(accountSection(pages.account.url, 'security'));

		const panel = await openPanel(page, '[data-diluxone-users-passkey="register"]');

		await panel.locator('[data-diluxone-users-passkey-label]').fill('Old name');
		await panel.locator('[data-diluxone-users-passkey="register"]').click();

		const label = page.locator('input[name="diluxone_users_passkey_label"]');

		await expect(label).toHaveValue('Old name', { timeout: 20_000 });
		await openAllPanels(page);

		await label.fill('The office desktop');
		await Promise.all([
			page.waitForURL(/diluxone-users=passkeyname/),
			page.locator('button[name="diluxone_users_passkey_do"][value="rename"]').first().click(),
		]);

		await expect(page.locator('input[name="diluxone_users_passkey_label"]')).toHaveValue('The office desktop');

		await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
	});

	test('a key the account no longer has does not open it', async ({ page, site, pages }) => {
		const email = freshEmail('passkey-gone');
		await site.makeUser({ email, password: PASSWORD });

		const cdp = await page.context().newCDPSession(page);

		await cdp.send('WebAuthn.enable', { enableUI: false });

		const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', { options: VIRTUAL_KEY });

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await page.goto(accountSection(pages.account.url, 'security'));

		const panel = await openPanel(page, '[data-diluxone-users-passkey="register"]');
		await panel.locator('[data-diluxone-users-passkey="register"]').click();

		// The page reloads itself once the key is stored, and the box it is
		// listed in starts closed again.
		await expect(page.locator('button[value="delete"]')).toHaveCount(1, { timeout: 20_000 });
		// The key is a box inside a box: the list of passkeys, and this key's
		// own row with its name and its buttons.
		await openAllPanels(page);

		// The person removes it — a lost phone, say. The browser still holds
		// the key; the site must no longer accept it.
		await Promise.all([
			page.waitForLoadState('domcontentloaded'),
			page.locator('button[value="delete"]').first().click(),
		]);

		await page.context().clearCookies();
		await page.goto(pages.login.url);
		await page.locator('[data-diluxone-users-passkey="login"]').click();

		// The page says so on the spot instead of navigating anywhere.
		await expect(page.locator('[data-diluxone-users-passkey-notice]')).toBeVisible({ timeout: 20_000 });
		await expectSignedOut(page);

		await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
	});
});
