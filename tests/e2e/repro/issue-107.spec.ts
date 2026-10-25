import { test, expect } from '../support/fixtures';
import { signInMember } from '../support/admin-content';

/**
 * Issue 107: with the menu at the side, "Above and below" pads only the top of
 * the content column, so whichever of the menu and the content is taller runs
 * flush into whatever follows the account area. The number typed is expected
 * to be the room below the row as well as above it.
 */

const ROOM = 40;

test.describe('Design › The account area › “Above and below”, with the menu at the side', () => {
	test('leaves the number typed between the end of the taller of menu and content and the end of the area', async ({ guest, site, pages, options }) => {
		await options.set({
			diluxone_users_account_template: 'plain',
			diluxone_users_account_layout: 'side',
			diluxone_users_account_width: 'contained',
			diluxone_users_account_body_pad: String(ROOM),
		});

		await signInMember(guest, site, pages.login.url, 'issue-107');
		await guest.goto(pages.account.url);

		const area = guest.locator('.diluxone-users-account');
		const tabs = guest.locator('.diluxone-users-account__nav--column .diluxone-users-account__tab');
		const section = guest.locator('.diluxone-users-account__section');

		await expect(area).toBeVisible();
		await expect(tabs.first(), 'the menu is down the side').toBeVisible();

		const areaBox = (await area.boundingBox())!;
		const lastTab = (await tabs.last().boundingBox())!;
		const content = (await section.boundingBox())!;

		const endOfTaller = Math.max(lastTab.y + lastTab.height, content.y + content.height);
		const below = areaBox.y + areaBox.height - endOfTaller;

		expect(below, 'room under the taller of the menu and the content, inside the area').toBeGreaterThanOrEqual(ROOM - 1);
	});
});
