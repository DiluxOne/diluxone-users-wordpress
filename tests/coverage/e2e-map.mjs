#!/usr/bin/env node
/**
 * Does the browser walk every door the plugin opens?
 *
 * A coverage driver cannot answer that for the end-to-end suites: they drive a
 * web server in another container, and what they prove is that a person got
 * through, not which lines ran. So this answers it the other way round. It
 * reads the plugin's code for everything it registers that a browser, a
 * person or another program can reach —
 *
 *   - every tab of every admin screen (`diluxone_users_register_panel()`),
 *   - every shortcode (`add_shortcode()`),
 *   - every admin-post and AJAX action (`admin_post_…`, `wp_ajax_…`),
 *   - every wp-login.php action (`login_form_…`),
 *   - every address of its own (`add_rewrite_rule()`, by the query variable it
 *     fills) and every REST route (`register_rest_route()`),
 *   - every WP-CLI command (`WP_CLI::add_command()`),
 *   - every template under templates/ —
 *
 * and fails when one is not mapped to a spec. A tab is mapped by being in
 * tests/e2e/support/screens.ts, the list three suites walk (behaviour, layout,
 * pictures); everything else by a row of the "Every door, by name" table in
 * tests/e2e/COVERAGE.md naming at least one spec, as `specs/<name>` or
 * `network/<name>`. It also fails on a row whose spec does not exist, and on a
 * row — or a tab in screens.ts — for something the plugin no longer registers:
 * a map that describes what the code no longer does is as wrong as a gap.
 *
 * Usage: node tests/coverage/e2e-map.mjs   (make coverage-e2e-map)
 */
import { readFileSync, readdirSync, existsSync, statSync } from 'node:fs';
import { join, relative, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SECTION = '## Every door, by name';

/** Every .php file under a directory, recursively. */
function phpFiles(dir) {
	const out = [];
	for (const name of readdirSync(dir).sort()) {
		const path = join(dir, name);
		if (statSync(path).isDirectory()) {
			out.push(...phpFiles(path));
		} else if (name.endsWith('.php')) {
			out.push(path);
		}
	}
	return out;
}

const sources = phpFiles(join(root, 'includes')).map((path) => ({
	file: relative(root, path),
	code: readFileSync(path, 'utf8'),
}));

/* The plugin's string constants, so `DILUXONE_USERS_SECURITY` reads as its slug. */
const constants = {};
for (const { code } of sources) {
	for (const m of code.matchAll(/^\s*const\s+([A-Z0-9_]+)\s*=\s*'([^']*)'\s*;/gm)) {
		constants[m[1]] = m[2];
	}
	for (const m of code.matchAll(/define\(\s*'([A-Z0-9_]+)'\s*,\s*'([^']*)'\s*\)/g)) {
		constants[m[1]] = m[2];
	}
}

/** A PHP argument that is a quoted string or one of the plugin's constants, as its value. */
function value(token, where) {
	token = token.trim();
	const quoted = token.match(/^'([^']*)'$/);
	if (quoted) {
		return quoted[1];
	}
	if (token in constants) {
		return constants[token];
	}
	throw new Error(`${where}: cannot read ${token} as a name; give it a literal or a constant`);
}

/* ── What the plugin registers ─────────────────────────────────────── */

const doors = new Map(); // "kind name" → { kind, name, where }
const tabs = new Map(); // "screen › tab" → where

function door(kind, name, where) {
	const key = `${kind} ${name}`;
	if (!doors.has(key)) {
		doors.set(key, { kind, name, where });
	}
}

const ARG = String.raw`('[^']*'|[A-Z0-9_]+)`;

for (const { file, code } of sources) {
	const line = (index) => `${file}:${code.slice(0, index).split('\n').length}`;

	for (const m of code.matchAll(new RegExp(String.raw`diluxone_users_register_panel\(\s*${ARG}\s*,\s*${ARG}`, 'g'))) {
		tabs.set(`${value(m[1], line(m.index))} › ${value(m[2], line(m.index))}`, line(m.index));
	}
	for (const m of code.matchAll(new RegExp(String.raw`add_shortcode\(\s*${ARG}`, 'g'))) {
		door('shortcode', value(m[1], line(m.index)), line(m.index));
	}
	for (const m of code.matchAll(/add_action\(\s*'(admin_post|wp_ajax)_(?:nopriv_)?([a-z0-9_]+)'\s*,/g)) {
		door(m[1] === 'admin_post' ? 'admin-post' : 'ajax', m[2], line(m.index));
	}
	for (const m of code.matchAll(new RegExp(String.raw`add_action\(\s*'login_form_(?:([a-z0-9_]+)'|'\s*\.\s*([A-Z0-9_]+))`, 'g'))) {
		door('wp-login', m[1] ?? value(m[2], line(m.index)), line(m.index));
	}
	for (const m of code.matchAll(/add_rewrite_rule\(([\s\S]*?)\);/g)) {
		const target = m[1].match(/([a-z0-9_]+)=\$matches|([A-Z0-9_]+)\s*\.\s*'=\$matches/);
		if (!target) {
			throw new Error(`${line(m.index)}: a rewrite rule that fills no query variable this script can name`);
		}
		door('address', target[1] ?? value(target[2], line(m.index)), line(m.index));
	}
	for (const m of code.matchAll(new RegExp(String.raw`register_rest_route\(\s*${ARG}\s*,\s*${ARG}`, 'g'))) {
		door('rest', `${value(m[1], line(m.index))}${value(m[2], line(m.index))}`, line(m.index));
	}
	for (const m of code.matchAll(/WP_CLI::add_command\(\s*'([^']+)'/g)) {
		door('wp-cli', `wp ${m[1]}`, line(m.index));
	}
}

for (const path of phpFiles(join(root, 'templates'))) {
	const file = relative(root, path);
	door('template', file, file);
}

/* ── What the map says ─────────────────────────────────────────────── */

const problems = [];

// The tabs: screens.ts, both lists.
const screensTs = readFileSync(join(root, 'tests/e2e/support/screens.ts'), 'utf8');
const listed = new Set();
for (const block of ['SCREENS', 'NETWORK_SCREENS']) {
	const body = screensTs.match(new RegExp(String.raw`export const ${block}[^=]*=\s*\{([\s\S]*?)\n\};`));
	if (!body) {
		problems.push(`tests/e2e/support/screens.ts: no ${block} to read`);
		continue;
	}
	for (const m of body[1].matchAll(/'([a-z0-9-]+)'\s*:\s*\[([^\]]*)\]/g)) {
		for (const tab of m[2].matchAll(/'([a-z0-9-]+)'/g)) {
			listed.add(`${m[1]} › ${tab[1]}`);
		}
	}
}
for (const [tab, where] of tabs) {
	if (!listed.has(tab)) {
		problems.push(`tab ${tab} (${where}) is not in tests/e2e/support/screens.ts, so no suite walks it`);
	}
}
for (const tab of listed) {
	if (!tabs.has(tab)) {
		problems.push(`tests/e2e/support/screens.ts lists ${tab}, which the plugin does not register`);
	}
}

// Everything else: the table in COVERAGE.md.
const coverage = readFileSync(join(root, 'tests/e2e/COVERAGE.md'), 'utf8');
const start = coverage.indexOf(`\n${SECTION}\n`);
const rows = new Map();
if (start < 0) {
	problems.push(`tests/e2e/COVERAGE.md has no "${SECTION}" section`);
} else {
	const rest = coverage.slice(start + SECTION.length + 2);
	const end = rest.search(/\n## /);
	const section = end < 0 ? rest : rest.slice(0, end);
	for (const raw of section.split('\n')) {
		const cells = raw.split('|').map((cell) => cell.trim());
		if (cells.length < 5 || !/^`/.test(cells[2] ?? '')) {
			continue;
		}
		const kind = cells[1];
		const name = cells[2].replace(/^`|`$/g, '');
		const specs = [...cells[3].matchAll(/`((?:specs|network)\/[a-z0-9-]+)`/g)].map((m) => m[1]);
		rows.set(`${kind} ${name}`, { kind, name, specs });
		if (specs.length === 0) {
			problems.push(`COVERAGE.md: ${kind} \`${name}\` names no spec (as \`specs/<name>\` or \`network/<name>\`)`);
		}
		for (const spec of specs) {
			if (!existsSync(join(root, 'tests/e2e', `${spec}.spec.ts`))) {
				problems.push(`COVERAGE.md: ${kind} \`${name}\` names ${spec}, and tests/e2e/${spec}.spec.ts does not exist`);
			}
		}
	}
}
for (const [key, { kind, name, where }] of doors) {
	if (!rows.has(key)) {
		problems.push(`${kind} \`${name}\` (${where}) has no row in COVERAGE.md › ${SECTION.slice(3)}`);
	}
}
for (const [key, { kind, name }] of rows) {
	if (!doors.has(key)) {
		problems.push(`COVERAGE.md has a row for ${kind} \`${name}\`, which the plugin does not register`);
	}
}

/* ── The answer ────────────────────────────────────────────────────── */

const count = (kind) => [...doors.values()].filter((d) => d.kind === kind).length;
console.log(
	`Tabs ${tabs.size}, shortcodes ${count('shortcode')}, admin-post ${count('admin-post')}, ajax ${count('ajax')}, ` +
		`wp-login ${count('wp-login')}, addresses ${count('address')}, REST ${count('rest')}, WP-CLI ${count('wp-cli')}, ` +
		`templates ${count('template')}.`,
);
if (problems.length > 0) {
	console.error(`\n✗ ${problems.length} gap${problems.length === 1 ? '' : 's'} in the end-to-end map:`);
	for (const problem of problems) {
		console.error(`  - ${problem}`);
	}
	process.exit(1);
}
console.log('✔ Every door the plugin registers is walked by a spec named in tests/e2e/COVERAGE.md.');
