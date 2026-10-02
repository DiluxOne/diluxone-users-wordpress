import { Page } from '@playwright/test';
import { expect, SiteHandle } from './support';
import { wp } from '../support/cli';
import { NETWORK_URL } from '../../../playwright.network.config';

/**
 * What the hub-* specs share: the network's screens, the membership queue and
 * the "Join this site" box, said once.
 */

/** An address of Network Admin. */
export const network = (rest: string): string => `${NETWORK_URL}/wp-admin/network/${rest.replace(/^\//, '')}`;

/** Network Admin › Membership. */
export const MEMBERSHIP = network('admin.php?page=diluxone-users-membership');

/** One of the three answers on the Membership screen. */
export function policy(page: Page, value: string) {
	return page.locator(`input[name="diluxone_users_membership"][value="${value}"]`);
}

/** How many jobs the membership queue holds, asked of the network. */
export function queued(): number {
	return Number(wp(['eval', 'echo count( diluxone_users_membership_queue() );'])) || 0;
}

/**
 * Lets WP-Cron work the queue to the end, as it would on its own.
 *
 * The container cannot reach its own address, so WordPress cannot spawn its
 * cron from a page load; WP-CLI runs what is due, which is what that spawn
 * does.
 */
export function drain(): void {
	for (let run = 0; run < 60 && queued() > 0; run++) {
		wp(['cron', 'event', 'run', '--due-now']);
	}

	expect(queued(), 'the queue is worked to the end').toBe(0);
}

/** The signed-in person's box on a page of a site: 'click', 'invite', 'joined', or null. */
export async function joinBox(page: Page): Promise<string | null> {
	const box = page.locator('[data-diluxone-users-join]').first();

	return (await box.count()) > 0 ? box.getAttribute('data-diluxone-users-join') : null;
}

/** A page of a site with the shortcode on it; deleted by the caller with forgetJoinPage(). */
export function joinPage(one: SiteHandle): { id: string; url: string } {
	const id = wp(['post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Join', '--post_content=[diluxone_users_join]', '--porcelain'], one.url);

	return { id, url: wp(['post', 'url', id], one.url) };
}

export function forgetJoinPage(one: SiteHandle, page: { id: string }): void {
	wp(['post', 'delete', page.id, '--force'], one.url);
}

/** A site's blog id. */
export function blogId(one: SiteHandle): number {
	return Number(wp(['eval', 'echo get_current_blog_id();'], one.url));
}

/** The live sites the membership counts: not archived, spam or deleted. */
export function liveSites(): number {
	return Number(wp(['site', 'list', '--archived=0', '--spam=0', '--deleted=0', '--format=count']));
}

/** The pill of a screen's rail, by its state class: 'active', 'pending', 'off'. */
export function railState(page: Page) {
	return page.locator('.du-state .diluxone-users-state').first();
}

/** A throwaway site of the network, made with WP-CLI and gone when `done` runs. */
export function throwawaySite(prefix: string): { slug: string; url: string; id: number; done: () => void } {
	const slug = `${prefix}-${Date.now().toString(36)}`;
	const id = Number(wp(['site', 'create', `--slug=${slug}`, `--title=${slug}`, '--porcelain']));

	return {
		slug,
		url: `${NETWORK_URL}/${slug}/`,
		id,
		done: () => {
			wp(['site', 'delete', String(id), '--yes']);
		},
	};
}

/** User meta as WP-CLI reads it, '' when there is none. */
export function userMeta(email: string, key: string): string {
	try {
		return wp(['user', 'meta', 'get', email, key, '--format=json']);
	} catch {
		return '';
	}
}
