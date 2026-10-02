import { Locator, Page, expect } from '@playwright/test';

/**
 * Where things are on the screen, said once.
 *
 * Everything here points at a name, an id or a class and never at a sentence.
 * The site this runs against is in Spanish, the plugin ships eight locales,
 * and a suite that looks for "Sign in" passes on one machine and fails on the
 * next. The markup is the contract; the wording is a setting.
 *
 * Where a test does have to prove that a particular message appeared, it
 * checks the class the template gives that message — `--error`, `--ok` — and
 * the `diluxone-users` state in the address, which is the plugin's own name
 * for what happened and does not translate.
 */

/* ── The sign-in page the plugin draws ─────────────────────────────── */

/** The "send me a link" form. */
export function linkForm(page: Page): Locator {
	return page.locator('form.diluxone-users-form').filter({ has: page.locator('input[name="diluxone_users_email"]') });
}

export function emailField(page: Page): Locator {
	return page.locator('input[name="diluxone_users_email"]');
}

/** WordPress's own password form, which the plugin's page draws inside itself. */
export function passwordForm(page: Page): Locator {
	return page.locator('form#loginform');
}

export function userField(page: Page): Locator {
	return page.locator('input[name="log"]');
}

export function passField(page: Page): Locator {
	return page.locator('input[name="pwd"]');
}

/** The screen that says a link is on its way. */
export function sentScreen(page: Page): Locator {
	return page.locator('.diluxone-users-login__email');
}

/** The second-step screen. */
export function challengeScreen(page: Page): Locator {
	return page.locator('.diluxone-users-login--2fa');
}

export function challengeCode(page: Page): Locator {
	return page.locator('input[name="diluxone_users_2fa_code"]');
}

/** The "choose a new password" screen the plugin draws on its own page. */
export function resetScreen(page: Page): Locator {
	return page.locator('.diluxone-users-login--reset');
}

/** The registration form. */
export function registerForm(page: Page): Locator {
	return page.locator('form.diluxone-users-form').filter({ has: page.locator('input[name="diluxone_users_email"]') });
}

export function registerScreen(page: Page): Locator {
	return page.locator('.diluxone-users-register');
}

/** A message the plugin put on the screen, by what kind it is. */
export function notice(page: Page, kind: 'error' | 'ok' | 'any' = 'any'): Locator {
	return page.locator(
		kind === 'any' ? '.diluxone-users-notice' : `.diluxone-users-notice--${kind}`
	);
}

/** The social buttons, whichever networks are on. */
export function ssoButtons(page: Page): Locator {
	return page.locator('a.diluxone-users-social');
}

/** One network's button, by the provider id in its class. */
export function ssoButton(page: Page, provider: string): Locator {
	return page.locator(`a.diluxone-users-social--${provider}`);
}

/* ── Doing things ──────────────────────────────────────────────────── */

/**
 * Presses a plugin form's button and waits to land on the answer.
 *
 * Every one of these forms posts to admin-post.php and comes back as a
 * redirect carrying `diluxone-users=<what happened>`. Reading the address
 * straight after the click reads the address before the trip: the wait is what
 * makes the assertion about the answer instead of about the timing.
 */
export async function submitPluginForm(page: Page, form: Locator): Promise<string> {
	await Promise.all([
		page.waitForURL(/[?&]diluxone-users=/, { waitUntil: 'domcontentloaded' }),
		form.locator('button[type="submit"], input[type="submit"]').first().click(),
	]);

	return new URL(page.url()).searchParams.get('diluxone-users') ?? '';
}

/** Asks for a sign-in link and waits for the screen that confirms it. */
export async function askForLink(page: Page, loginUrl: string, email: string): Promise<void> {
	await page.goto(loginUrl);
	await openWay(page, 'email');
	await emailField(page).fill(email);
	await linkForm(page).locator('button[type="submit"]').click();
	await expect(sentScreen(page)).toContainText(email);
}

/**
 * Opens the way in that holds a form, when the sign-in page is showing tabs.
 *
 * Stacked, every way in is on the screen at once and there is nothing to
 * press. In tabs they share one place and the ones that are not open carry
 * `hidden`, so filling a field without this fills a field nobody can see —
 * which is exactly how this suite found out that the arrangement had arrived:
 * `input[name="log"]` resolved, and Playwright waited a minute for a box
 * inside a closed tab to become visible.
 *
 * The tab is found by the id the plugin puts on it, never by its label: this
 * site runs in Spanish and the plugin ships eight locales. A page with no
 * strip, or with that way already open, costs nothing and does nothing —
 * which is what lets wp-login.php, where there are no ways at all, go through
 * the same helper.
 */
export async function openWay(page: Page, way: string): Promise<void> {
	// No strip on the screen — stacked, where the strip is drawn hidden, or
	// wp-login.php — is nothing to press. A strip without this way is a page
	// that does not offer it, and the step that wanted it fails here, saying
	// so, not a minute later on a field.
	if ((await page.locator('[data-diluxone-users-way-tab]:visible').count()) === 0) {
		return;
	}

	const tab = page.locator(`[data-diluxone-users-way-tab="${way}"]`);

	await expect(tab, `the ${way} tab`).toBeVisible();

	if ((await tab.getAttribute('aria-selected')) === 'true') {
		return;
	}

	await tab.click();
	await expect(page.locator(`[data-diluxone-users-way="${way}"]`)).toBeVisible();
}

/** Signs in with a password through whichever form is on the page. */
export async function signInWithPassword(page: Page, user: string, pass: string): Promise<void> {
	await openWay(page, 'password');
	// Typed and read back until both are where they were typed: on
	// wp-login.php the page focuses the username box on a timer of its own,
	// and a password typed in that moment lands in the wrong box.
	await fillCredentials(page, user, pass);
	await passwordForm(page).locator('input[type="submit"], button[type="submit"]').first().click();
}

/**
 * Types a username and a password into wp-login.php's own form, and checks
 * they are both where they were typed before anything is pressed.
 *
 * wp-login.php focuses and selects the username box from a timer of its own,
 * 200 ms after the page draws. A fill that lands in the same moment can end
 * up with the password in the username box, and the setup then waits a minute
 * for a dashboard that a wrong password never opens. So the two values are
 * read back, and typed again until they are right.
 */
export async function fillCredentials(page: Page, user: string, pass: string): Promise<void> {
	const login = page.locator('input[name="log"]');
	const password = page.locator('input[name="pwd"]');

	await expect(async () => {
		await login.fill(user);
		await password.fill(pass);
		await expect(login).toHaveValue(user, { timeout: 500 });
		await expect(password).toHaveValue(pass, { timeout: 500 });
	}).toPass({ timeout: 10_000 });
}

/** Signs out, whatever the session is. */
export async function signOut(page: Page): Promise<void> {
	await page.context().clearCookies();
}

/* ── The account area ──────────────────────────────────────────────── */

/**
 * Opens the box on an account screen that holds a given thing.
 *
 * Every block there is a `<details>` and most of them start closed, so what a
 * person does first is press the heading. Pressing it when it is already open
 * would close it, which is why the state is asked before the click.
 *
 * @param inner A selector for something inside the box you want.
 */
export async function openPanel(page: Page, inner: string): Promise<Locator> {
	const panel = page
		.locator('details.diluxone-users-panel')
		.filter({ has: page.locator(inner) })
		.first();

	await expect(panel).toBeAttached();

	// `> summary` and not `summary`: some rows inside a box are boxes of their
	// own — a passkey with its details — and a plain descendant selector picks
	// up theirs as well.
	if (!(await panel.evaluate((element: HTMLDetailsElement) => element.open))) {
		await panel.locator('> summary').click();
	}

	await expect(panel.locator(inner).first()).toBeVisible();

	return panel;
}

/**
 * Opens every box on the screen.
 *
 * For the screens that split one list across two boxes — the networks already
 * linked, and the ones still available — where a given row is depends on the
 * state of the account, which is the thing the test is about to change.
 */
export async function openAllPanels(page: Page): Promise<void> {
	// Outermost first, and read again each round: opening one box is what makes
	// the boxes inside it clickable at all.
	for (let round = 0; round < 3; round++) {
		const closed = page.locator('details:not([open])');

		if ((await closed.count()) === 0) {
			return;
		}

		for (let n = 0; n < (await closed.count()); n++) {
			const one = closed.nth(n);

			if (await one.locator('> summary').isVisible()) {
				await one.locator('> summary').click();
			}
		}
	}

	// Whatever a person could still open is open: no closed box left on the
	// screen with its heading in reach.
	await expect(page.locator('details:not([open]) > summary:visible'), 'a box left closed').toHaveCount(0);
}

/** The address of one section of the account area. */
export function accountSection(accountUrl: string, section: string): string {
	return `${accountUrl.replace(/\/?$/, '/')}${section}/`;
}

/* ── The dashboard ─────────────────────────────────────────────────── */

/**
 * One of the plugin's settings screens, on one of its tabs.
 *
 * The third argument is for the screens that draw what the site is doing
 * rather than what it is set to: a search that pins the list to one seeded
 * person is the difference between a picture of a screen and a picture of
 * whatever happened to be true when it was taken.
 *
 * @param query Extra query arguments, appended in the order given.
 */
export function adminUrl(screen: string, tab?: string, query: Record<string, string> = {}): string {
	const extra = Object.entries(query)
		.map(([key, value]) => `&${encodeURIComponent(key)}=${encodeURIComponent(value)}`)
		.join('');

	return `/wp-admin/admin.php?page=${screen}${tab ? `&tab=${tab}` : ''}${extra}`;
}

/** One of the tick boxes that say what the sign-in form takes. */
export function loginWay(page: Page, way: 'link' | 'password'): Locator {
	return page.locator(`input[name="diluxone_users_login_method[]"][value="${way}"]`);
}

/**
 * A group of tick boxes where none ticked is not an answer.
 *
 * The plugin marks these in the markup — `data-diluxone-users-atleast-one` —
 * and the script adds `is-short` to the one it is stopping the form over. The
 * sentence beside it is written by the server in the site's own language, so
 * the class is the hold and the words are never read.
 */
export function needsOne(page: Page, name: string): Locator {
	return page
		.locator('[data-diluxone-users-atleast-one]')
		.filter({ has: page.locator(`input[name="${name}"]`) })
		.first();
}

/**
 * Sends a panel's form the way nothing on the page can intervene in.
 *
 * `HTMLFormElement.submit()` fires no submit event, so the courtesy in the
 * browser never runs and the request reaches the server exactly as it would
 * with the script switched off. That is the only way to ask whether the save
 * itself refuses, which is the half that matters: the script is a kindness,
 * the server is the rule.
 */
export async function submitPanelWithoutScript(page: Page): Promise<void> {
	await navigated(page, () =>
		saveButton(page).evaluate((button: HTMLButtonElement) => {
			// The button sits in the column beside the form and reaches it by
			// its `form` attribute, which `button.form` resolves.
			HTMLFormElement.prototype.submit.call(button.form as HTMLFormElement);
		})
	);
}

/**
 * The button that saves the tab.
 *
 * It is in the box at the head of the column beside the settings, on every
 * tab that saves, and its words are translated eight ways: the box is the
 * stable hold. A tab with two forms has two buttons; the first is the tab's.
 */
export function saveButton(page: Page): Locator {
	return page.locator('[data-diluxone-users-save] .du-save__button').first();
}

/** What the dashboard shows when a save was refused. */
export function adminError(page: Page): Locator {
	return page.locator('.notice-error');
}

/**
 * What the dashboard shows when a save went through.
 *
 * The counterpart of `adminError`, and the only honest way to ask "did it
 * congratulate itself": the sentence inside is translated eight ways, the
 * class the notice is printed with is not.
 */
export function adminSaved(page: Page): Locator {
	return page.locator('.notice-success');
}

/**
 * Does something that leaves the page — a form sent, a link followed — and
 * waits for the page it lands on.
 *
 * `Promise.all([page.waitForLoadState('domcontentloaded'), click])` looks like
 * this and is not: the page already reached that state before the click, so
 * the wait resolves at once and the next line reads the page being left. What
 * is waited for here is the next document of the main frame, after any
 * redirects, and then its DOM.
 */
export async function navigated(page: Page, action: () => Promise<unknown>): Promise<void> {
	await Promise.all([page.waitForEvent('framenavigated', (frame) => frame === page.mainFrame()), action()]);
	await page.waitForLoadState('domcontentloaded');
}

/**
 * Does something that has to ask first, answers the question, and returns it.
 *
 * `page.once('dialog', …)` alone passes just as well when nothing is asked:
 * the handler waits for a dialog that never comes and the click goes through
 * unasked. Here the question is required — it has to come, within ten
 * seconds, and say something — or the step fails.
 */
export async function answeringDialog(page: Page, answer: 'accept' | 'dismiss', action: () => Promise<unknown>): Promise<string> {
	const asked = new Promise<string>((resolve, reject) => {
		const timer = setTimeout(() => reject(new Error('Nothing was asked before going ahead.')), 10_000);

		page.once('dialog', async (dialog) => {
			clearTimeout(timer);
			const message = dialog.message();
			await dialog[answer]();
			resolve(message);
		});
	});

	const [, message] = await Promise.all([action(), asked]);

	expect(message, 'the question asked first').not.toBe('');

	return message;
}

/**
 * Presses the button that saves the tab, and requires the save to have gone
 * through.
 *
 * The form posts to the screen it is on, which draws itself again with the
 * outcome. So what is waited for is the answer to that POST — not the load
 * state, which the page already had before the click and which resolves at
 * once — and then the success notice, with no error beside it. Any notice
 * would not do: an error is a notice too, and so is one that was on the
 * screen before anything was pressed.
 */
export async function savePanel(page: Page): Promise<void> {
	await Promise.all([
		page.waitForResponse((response) => response.request().method() === 'POST' && response.request().resourceType() === 'document'),
		saveButton(page).click(),
	]);
	await page.waitForLoadState('domcontentloaded');

	await expect(adminSaved(page)).toBeVisible();
	await expect(adminError(page)).toHaveCount(0);
}
