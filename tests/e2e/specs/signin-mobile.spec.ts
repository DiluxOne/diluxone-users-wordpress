import { Page } from '@playwright/test';
import { test, expect, expectSignedIn } from '../support/fixtures';
import { freshEmail, linkIn, waitForMail } from '../support/api';
import { challengeScreen, registerForm, resetScreen, signInWithPassword } from '../support/ui';
import { FRONT_RULES, expectSoundLayout, sidewaysScroll } from '../support/layout';
import { MOCK_ON } from '../support/signin';

/**
 * The ways in on a phone, 390 pixels wide.
 *
 * The stylesheets change below 782, 640, 560 and 480 pixels and no other spec
 * draws anything that narrow: the split shape stacks and drops its picture,
 * the tab labels give way to their icons, two social buttons a row become one,
 * and the registration form, the second step and the reset screen have to fit.
 * Behaviour, not pictures: what is hidden, what is stacked, what still works,
 * and that nothing scrolls sideways.
 */

const PHONE = { width: 390, height: 844 };
const PASSWORD = 'e2e-Phone-1!';

test.use({ viewport: PHONE });

/** Nothing on the page scrolls sideways. */
async function fitsTheWidth(page: Page): Promise<void> {
	expect(await sidewaysScroll(page), 'the page scrolls sideways').toBeLessThanOrEqual(1);
}

test.describe('The sign-in page on a phone', () => {
	test('the split shape stacks: no picture beside the form, the form as wide as the screen allows', async ({ page, pages, options }) => {
		await options.set({ diluxone_users_login_template: 'split', diluxone_users_login_panel_title: '', diluxone_users_login_panel_text: '', diluxone_users_login_panel_points: '', diluxone_users_login_panel_foot: '' });

		// On a laptop the panel is there, beside the form.
		await page.setViewportSize({ width: 1280, height: 900 });
		await page.goto(pages.login.url);
		await expect(page.locator('.diluxone-users-login-frame__picture')).toBeVisible();

		await page.setViewportSize(PHONE);
		await expect(page.locator('.diluxone-users-login-frame--split')).toBeVisible();
		await expect(page.locator('.diluxone-users-login-frame__picture'), 'the picture is the first thing to go').toBeHidden();

		const box = await page.locator('.diluxone-users-login-frame__box').boundingBox();
		expect(box!.width, 'the form takes the width, not half of it').toBeGreaterThan(PHONE.width * 0.8);
		await fitsTheWidth(page);
	});

	test('the split shape with words on its panel keeps them, above the form', async ({ page, pages, options }) => {
		await options.set({ diluxone_users_login_template: 'split', diluxone_users_login_panel_title: 'E2E one account', diluxone_users_login_panel_text: 'E2E no passwords' });

		await page.goto(pages.login.url);

		const words = page.locator('.diluxone-users-login-frame__picture--words');
		await expect(words).toBeVisible();

		const wordsBox = await words.boundingBox();
		const formBox = await page.locator('.diluxone-users-login-frame__box').boundingBox();
		expect(wordsBox!.y + wordsBox!.height, 'stacked above the form, not beside it').toBeLessThanOrEqual(formBox!.y + 1);
		await fitsTheWidth(page);
	});

	test('the tabs keep their icons and lose their labels, and still open their panels', async ({ page, pages, options }) => {
		await options.set({
			diluxone_users_login_layout: 'tabs',
			diluxone_users_login_method: 'both',
			diluxone_users_passkey_enabled: 1,
			diluxone_users_sso_login: 1,
			diluxone_e2e_sso: 1,
			diluxone_users_sso: MOCK_ON,
		});

		await page.goto(pages.login.url);

		const strip = page.locator('[data-diluxone-users-ways-strip]');
		await expect(strip).toBeVisible();

		const labels = page.locator('.diluxone-users-ways__tab span');
		expect(await labels.count()).toBeGreaterThan(1);

		// On a laptop the labels are read beside the icons.
		await page.setViewportSize({ width: 1280, height: 900 });
		expect(await labels.first().evaluate((one) => one.getBoundingClientRect().width)).toBeGreaterThan(10);
		await page.setViewportSize(PHONE);

		for (const size of await labels.evaluateAll((all) => all.map((one) => one.getBoundingClientRect().width))) {
			expect(size, 'a label folded down to a pixel').toBeLessThanOrEqual(1);
		}

		// Still named for a screen reader: the label is in the markup.
		await expect(labels.first()).not.toBeEmpty();

		const stripBox = await strip.boundingBox();
		expect(stripBox!.x + stripBox!.width, 'the strip fits the screen').toBeLessThanOrEqual(PHONE.width + 1);

		const password = page.locator('[data-diluxone-users-way-tab="password"]');
		await password.click();
		await expect(page.locator('[data-diluxone-users-way="password"]')).toBeVisible();
		await fitsTheWidth(page);
	});

	test('two social buttons a row become one a row', async ({ page, pages, options }) => {
		await options.set({
			diluxone_users_sso_login: 1,
			diluxone_e2e_sso: 1,
			diluxone_users_sso: { ...MOCK_ON, google: { active: 1, id: 'e2e-google-id', secret: 'e2e-google-secret', tested: 1 } },
			diluxone_users_sso_button_columns: 2,
		});

		await page.goto(pages.login.url);

		const buttons = page.locator('.diluxone-users-socials--cols-2 a.diluxone-users-social');
		await expect(buttons).toHaveCount(2);

		const [one, two] = await buttons.evaluateAll((all) => all.map((b) => b.getBoundingClientRect()).map((r) => ({ x: r.x, y: r.y, w: r.width })));
		expect(Math.abs(one.x - two.x), 'one above the other').toBeLessThanOrEqual(1);
		expect(two.y, 'the second under the first').toBeGreaterThan(one.y);

		// The same two, wide: side by side.
		await page.setViewportSize({ width: 1280, height: 900 });
		const [wideOne, wideTwo] = await buttons.evaluateAll((all) => all.map((b) => b.getBoundingClientRect()).map((r) => ({ x: r.x, y: r.y })));
		expect(Math.abs(wideOne.y - wideTwo.y), 'side by side on a laptop').toBeLessThanOrEqual(1);
		expect(wideTwo.x).toBeGreaterThan(wideOne.x);
	});
});

test.describe('The other screens of the ways in on a phone', () => {
	test('the registration form fits and holds together, and it still makes the account', async ({ page, site, pages, options }) => {
		await options.set({
			diluxone_users_register_form: 1,
			diluxone_users_fields: [{ key: 'first_name', label: 'First name', type: 'text', required: 1, active: 1, group: 'main', edit: 'always' }],
		});

		await page.goto(pages.register.url);
		await expect(registerForm(page)).toBeVisible();
		await expectSoundLayout(page, FRONT_RULES, [PHONE.width]);

		const email = freshEmail('phone-reg');
		await registerForm(page).locator('input[name="diluxone_users_email"]').fill(email);
		await registerForm(page).locator('input[name="diluxone_users_first_name"], input[name="first_name"]').first().fill('Phone');
		await Promise.all([page.waitForURL(/diluxone-users=registered/), registerForm(page).locator('button[type="submit"]').click()]);
		expect((await site.user(email)).exists).toBe(true);
	});

	test('the second step fits and holds together, and its code gets in', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_mode: 'required', diluxone_users_2fa_methods: ['email'], diluxone_users_2fa_remember_days: 30 });

		const email = freshEmail('phone-2fa');
		await site.makeUser({ email, password: PASSWORD });

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expect(challengeScreen(page)).toBeVisible();
		await expectSoundLayout(page, FRONT_RULES, [PHONE.width]);

		const code = (await waitForMail(site, email)).body.match(/\b(\d{6})\b/)![1];
		await page.locator('input[name="diluxone_users_2fa_code"]').fill(code);
		await page.locator('.diluxone-users-login--2fa form.diluxone-users-form button[type="submit"]').first().click();
		await expectSignedIn(page, email);
	});

	test('the reset screen fits and holds together', async ({ page, site, options }) => {
		await options.set({ diluxone_users_lost_password: 'site', diluxone_users_login_method: 'both' });

		const email = freshEmail('phone-reset');
		await site.makeUser({ email, password: PASSWORD });

		await page.goto('/wp-login.php?action=lostpassword');
		await page.locator('input[name="user_login"]').fill(email);
		await page.locator('#wp-submit').click();
		await page.goto(linkIn(await waitForMail(site, email, { subject: /contrase|password/i })));

		await expect(resetScreen(page)).toBeVisible();
		await expectSoundLayout(page, FRONT_RULES, [PHONE.width]);
	});
});
