import { execFileSync } from 'node:child_process';
import { basename } from 'node:path';

/**
 * WP-CLI inside the wp-env TESTS container — the network suite's other hand.
 *
 * A browser can do everything a person does, and the REST side door in the
 * mu-plugin everything a test needs on one site. What neither can do is act on
 * the network itself: make a site, read a network option, look at
 * wp-content/debug.log. That is what this is for, and nothing else: anything a
 * person could do is done in the browser.
 *
 * It shells out to `npx wp-env run`, so it has to run from the repository
 * root — which is where Playwright runs from.
 */

/**
 * The plugin's folder inside the container. wp-env mounts the checkout under
 * its own directory name — the repository's, or a worktree's — which is the
 * directory Playwright runs from.
 */
export const PLUGIN_DIR = basename(process.cwd());

/** wp-env prints its own progress lines on stdout; they are not the answer. */
const NOISE = /^[ℹ✔✖⚠]|^- |^Starting |^Ran `/;

function run(args: string[]): string {
	const out = execFileSync('npx', ['wp-env', 'run', 'tests-cli', ...args], {
		encoding: 'utf8',
		stdio: ['ignore', 'pipe', 'pipe'],
		maxBuffer: 64 * 1024 * 1024,
		env: { ...process.env, WP_ENV_DEBUG: '' },
	});

	return out
		.split('\n')
		.filter((line) => !NOISE.test(line))
		.join('\n')
		.trim();
}

/** A WP-CLI command; `url` picks the site of the network it runs against. */
export function wp(args: string[], url?: string): string {
	return run(['wp', ...args, ...(url ? [`--url=${url}`] : [])]);
}

/** A shell command inside the tests CLI container. */
export function shell(command: string): string {
	return run(['bash', '-c', command]);
}

/** Where WP_DEBUG_LOG writes, inside the container. */
export const DEBUG_LOG = '/var/www/html/wp-content/debug.log';

/**
 * How many lines the debug log has right now.
 *
 * The log is shared by every site of the network and by everything else the
 * container ever did, so a test never asks "is it empty" — it counts before,
 * does its thing, and asks for whatever was added after that count.
 */
export function debugLogLines(): number {
	return Number(shell(`[ -f ${DEBUG_LOG} ] && wc -l < ${DEBUG_LOG} || echo 0`)) || 0;
}

/** The lines added to the debug log since `from`. */
export function debugLogSince(from: number): string[] {
	const out = shell(`[ -f ${DEBUG_LOG} ] && tail -n +${from + 1} ${DEBUG_LOG} || true`);

	return out ? out.split('\n').filter((line) => line.trim() !== '') : [];
}
