import { Page, expect } from '@playwright/test';

/**
 * The fourth suite: what a screen looks like, asked of the browser.
 *
 * The other three ask whether the code behaves. They answer that well, and
 * every visual bug this plugin has shipped got past all three of them green:
 * a block drawn on top of the card above it, half a screen of nothing beside
 * a column of settings, a bordered box with nothing inside it, a rail that
 * fell underneath the form it belongs beside. None of those is a wrong value
 * or a missing hook. They are geometry, and only a browser can see geometry.
 *
 * So this measures it. Not "does it look nice" — no test can hold an opinion,
 * and one that tried would be a test that fails whenever the design improves.
 * These are the four or five things that are never right by accident and
 * never wrong on purpose:
 *
 *   overlap   two blocks that are side by side in the markup, sharing pixels
 *   overflow  something reaching past the right-hand edge of the screen
 *   air       two blocks of a screen touching, with no space between them
 *   blank     a box with a border or a ground and nothing inside it
 *   rail      the second column, when a screen declares one, beside and not below
 *   shape     what that column is made of: the three pieces, in their order
 *
 * Every one of them is a number compared with a number, which is why this is
 * the cheap half of the visual suite: it needs no baseline image, it says
 * exactly which element is wrong, and it means the same thing on every
 * machine. The snapshots in `admin-snapshots.spec.ts` are the other half.
 */

/**
 * The widths that matter.
 *
 * Two of them are WordPress's own: at 960px the dashboard folds its menu to
 * icons, at 782px it goes to the phone layout and the plugin's second column
 * has to give up and go underneath. The other two are the desk this is
 * designed on and the laptop it is read on. A layout that holds at four
 * widths holds; one measured at a single width is a screenshot.
 */
export const WIDTHS = [1600, 1280, 960, 782] as const;

/**
 * The widths of the public pages: the four above, and the three the plugin's
 * own stylesheet for the front end changes at — 640, 560 and 480, where the
 * account's menu, the registration's columns and the social buttons each
 * restack — and a phone, 390, where every one of them has taken over. The
 * dashboard has no rules of its own below 782, so it is not measured there.
 */
export const FRONT_WIDTHS = [...WIDTHS, 640, 560, 480, 390] as const;

export type LayoutKind = 'overlap' | 'overflow' | 'air' | 'blank' | 'rail' | 'shape' | 'hidden' | 'root';

export interface LayoutFinding {
	kind: LayoutKind;
	/** The element, as a trail of tags and classes down from the root. */
	where: string;
	detail: string;
}

export interface LayoutRules {
	/** The part of the page that belongs to the plugin. */
	root: string;
	/**
	 * The containers whose children are the blocks of a screen.
	 *
	 * Air is only asked for between those: two options inside a group touch
	 * each other on purpose, two sections of a screen never do. Overlap, by
	 * contrast, is asked of every container there is — two boxes sharing
	 * pixels is wrong wherever it happens.
	 */
	flow: string[];
	/** The smallest gap, in pixels, that counts as space between two blocks. */
	minAir: number;
	/**
	 * What the air rule steps over.
	 *
	 * The title and the tab strip are the dashboard's own header: core draws
	 * them, core styles them, and they sit flush against each other on every
	 * settings screen WordPress ships. Asking this plugin for space there
	 * would be asking it to stop looking like the dashboard.
	 */
	exempt: string[];
	/**
	 * The width below which a second column stops being a second column.
	 *
	 * It is the number in the stylesheet's media query, said once here so the
	 * rule about where the rail belongs is asked the same way the stylesheet
	 * answers it.
	 */
	railAt: number;
}

export const ADMIN_RULES: LayoutRules = {
	root: '.wrap.diluxone-users-admin',
	flow: [
		'.wrap.diluxone-users-admin',
		'.diluxone-users-studio__fields',
		'.diluxone-users-studio__aside',
	],
	// The design system's smallest step between two blocks is 12px
	// (`--du-3`). Eight is the floor this asks for, so a screen can be
	// tightened by a step without a test having an opinion about it, and a
	// screen with no margin at all is still caught.
	minAir: 8,
	exempt: ['h1', '.nav-tab-wrapper', '.notice', '.updated', '.error'],
	railAt: 960,
};

export const FRONT_RULES: LayoutRules = {
	root: '.diluxone-users-login, .diluxone-users-register, .diluxone-users-account',
	flow: ['.diluxone-users-login', '.diluxone-users-register', '.diluxone-users-account'],
	minAir: 4,
	exempt: [],
	railAt: 960,
};

/**
 * Reads the geometry of one screen and says what is wrong with it.
 *
 * All of it runs inside the page, in one call: reading a rectangle from the
 * test side is a round trip each, and a screen has upwards of three hundred
 * elements on it. What comes back is a list of sentences, not a boolean —
 * a failure has to say which block, or nobody can act on it.
 */
export async function layoutFindings(
	page: Page,
	rules: LayoutRules = ADMIN_RULES
): Promise<LayoutFinding[]> {
	// Scrolled to the top and with the layout settled: a sticky preview reads
	// as displaced halfway down the page, and a measurement taken while the
	// browser is still laying out is a measurement of nothing.
	await page.evaluate(() => {
		window.scrollTo(0, 0);

		return new Promise<void>((done) => requestAnimationFrame(() => requestAnimationFrame(() => done())));
	});

	return page.evaluate((look: LayoutRules): LayoutFinding[] => {
		const found: LayoutFinding[] = [];
		const host = document.querySelector<HTMLElement>(look.root);

		if (!host) {
			return [{ kind: 'root', where: look.root, detail: 'the page has no such block on it' }];
		}

		/* A pixel of slack. Borders are drawn on the boundary, a grid gap
		   lands on a fraction, and a browser rounds where a stylesheet did
		   not. Two blocks sharing one pixel is arithmetic; sharing two is a
		   mistake. */
		const slack = 1;

		/* What a finding calls an element: enough to find it in the markup,
		   short enough to read in a terminal. */
		const label = (el: Element): string => {
			const classes =
				typeof el.className === 'string' && el.className.trim() !== ''
					? `.${el.className.trim().split(/\s+/).slice(0, 3).join('.')}`
					: '';

			return `${el.tagName.toLowerCase()}${el.id !== '' ? `#${el.id}` : ''}${classes}`;
		};

		const trail = (el: Element): string => {
			const parts: string[] = [];

			for (let node: Element | null = el; node && node !== host; node = node.parentElement) {
				parts.unshift(label(node));
			}

			return parts.length > 0 ? parts.join(' › ') : label(host);
		};

		const box = (el: Element): DOMRect => el.getBoundingClientRect();

		const drawn = (el: Element, style: CSSStyleDeclaration): boolean => {
			const rect = box(el);

			return (
				style.display !== 'none' &&
				style.visibility !== 'hidden' &&
				style.opacity !== '0' &&
				rect.width > 0.5 &&
				rect.height > 0.5
			);
		};

		/* A block for these purposes is something that takes a line of its
		   own. Inline boxes are left out on purpose: two spans that wrap
		   across the same line have rectangles that genuinely overlap and
		   nothing is wrong, which is the one false alarm this would otherwise
		   produce on every screen with a sentence on it. */
		const BLOCK = ['block', 'flex', 'grid', 'list-item', 'flow-root', 'table'];

		/* Taken out of the flow by its stylesheet, so where it lands is not
		   the flow's business. Sticky stays in: at the top of the page it is
		   exactly where the flow put it, and that is where this measures. */
		const inFlow = (style: CSSStyleDeclaration): boolean =>
			style.position !== 'absolute' &&
			style.position !== 'fixed' &&
			style.float === 'none' &&
			style.transform === 'none';

		/* Where the walk stops. A table lays itself out by rules of its own —
		   collapsed borders make adjacent cells share an edge — and the
		   controls are drawn by the browser, not by this stylesheet. Each is
		   still measured as one block inside its parent; what is inside them
		   is not this suite's business. */
		const OPAQUE = new Set([
			'table', 'svg', 'iframe', 'select', 'textarea', 'input', 'button', 'canvas', 'video', 'img',
		]);

		/* Things that are drawings rather than blocks: a bar that fills up, a
		   square of colour, the mark on a switch, the little picture of a
		   layout on the frames chooser — which is a whole page drawn in
		   rules and pseudo-elements and has no words in it by design. They
		   are meant to be empty; that is what they are. So the "bordered box
		   with nothing in it" rule steps over them by name, which is a list
		   somebody has to add to deliberately, rather than by guessing at
		   what looks decorative. */
		const DELIBERATELY_BLANK = [
			'.du-bar', '.du-bar__fill', '.du-swatch', '.du-toggle', '.du-toggle__knob',
			'.diluxone-users-toggle', '.diluxone-users-bar', '.diluxone-users-swatch',
			'.diluxone-users-stage', '.diluxone-users-studio__preview',
			'.diluxone-users-templates__art',
		];

		const decorative = (el: Element): boolean =>
			DELIBERATELY_BLANK.some((one) => el.matches(one) || el.closest(one) !== null);

		const paints = (style: CSSStyleDeclaration): boolean => {
			const ground =
				style.backgroundImage !== 'none' ||
				(style.backgroundColor !== 'rgba(0, 0, 0, 0)' && style.backgroundColor !== 'transparent');

			const edge = (['Top', 'Right', 'Bottom', 'Left'] as const).some((side) => {
				const width = parseFloat(style.getPropertyValue(`border-${side.toLowerCase()}-width`));
				const colour = style.getPropertyValue(`border-${side.toLowerCase()}-color`);

				return width > 0 && colour !== 'rgba(0, 0, 0, 0)' && colour !== 'transparent';
			});

			return ground || edge;
		};

		const isFlow = (el: Element): boolean => look.flow.some((one) => el.matches(one));

		/* ── The walk ───────────────────────────────────────────────────── */

		const rootBox = box(host);
		const stack: Array<{ el: Element; clipped: boolean }> = [{ el: host, clipped: false }];

		while (stack.length > 0) {
			const here = stack.pop()!;
			const parent = here.el;
			const kids: Array<{ el: Element; rect: DOMRect }> = [];

			for (const child of Array.from(parent.children)) {
				const style = getComputedStyle(child);

				if (!drawn(child, style)) {
					continue;
				}

				const rect = box(child);

				/* Hidden means hidden, and the browser's own rule for the
				   attribute has the weight of a bare tag — so any component
				   that gives itself a `display` outranks it and the thing
				   stays on the screen with nothing in it. Three components in
				   this plugin found that out separately and each answered it
				   with a rule of its own, one of them an `!important`; the two
				   stylesheets say it once now, at the bottom, where a rule
				   that takes something off the screen can outrank the
				   component that put it there.

				   This is what says it stayed said, on every screen and at
				   every width, in a browser CI is allowed to run — which the
				   pictures are not. It is here because consolidating those
				   three rules broke one of them, quietly: the list of roles
				   under "Everybody" came back, every measurement passed, and
				   the only thing that noticed was a photograph. */
				if (child.hasAttribute('hidden')) {
					found.push({
						kind: 'hidden',
						where: trail(child),
						detail: `carries the hidden attribute and is still ${Math.round(rect.width)}×${Math.round(rect.height)} on the screen`,
					});
				}

				/* Nothing the plugin draws may reach past the edge of its own
				   block. A screen can scroll a table sideways on purpose, so
				   anything inside something that scrolls is exempt. */
				if (!here.clipped && rect.right > rootBox.right + slack) {
					found.push({
						kind: 'overflow',
						where: trail(child),
						detail: `reaches ${Math.round(rect.right - rootBox.right)}px past the right edge of ${look.root}`,
					});
				}

				/* A box with a border or a ground and nothing in it is the
				   thing this design system spent a round taking off these
				   screens. Small ones are rules and marks; this is about the
				   ones big enough to read as a card. */
				if (
					!OPAQUE.has(child.tagName.toLowerCase()) &&
					rect.width >= 48 &&
					rect.height >= 24 &&
					(child.textContent ?? '').trim() === '' &&
					child.querySelector('img, svg, canvas, iframe, input, select, textarea, button') === null &&
					paints(style) &&
					!decorative(child)
				) {
					found.push({
						kind: 'blank',
						where: trail(child),
						detail: `${Math.round(rect.width)}×${Math.round(rect.height)} with a border or a ground and nothing inside it`,
					});
				}

				if (BLOCK.includes(style.display) && inFlow(style)) {
					kids.push({ el: child, rect });
				}

				if (!OPAQUE.has(child.tagName.toLowerCase())) {
					const scrolls = style.overflowX === 'auto' || style.overflowX === 'scroll' || style.overflowX === 'hidden';

					stack.push({ el: child, clipped: here.clipped || scrolls });
				}
			}

			/* ── No two blocks share pixels ──────────────────────────────
			   This is the one that matters. "A quién" drawn on top of the
			   card above it is two sibling rectangles intersecting, and
			   nothing but a browser can tell you that. It is asked of every
			   pair, not only of neighbours, because a block with a negative
			   margin lands on the one before the one before it. */
			for (let i = 0; i < kids.length; i++) {
				for (let j = i + 1; j < kids.length; j++) {
					const a = kids[i].rect;
					const b = kids[j].rect;
					const across = Math.min(a.right, b.right) - Math.max(a.left, b.left);
					const down = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);

					if (across > slack && down > slack) {
						found.push({
							kind: 'overlap',
							where: `${trail(kids[i].el)}  ✕  ${trail(kids[j].el)}`,
							detail: `sharing ${Math.round(across)}×${Math.round(down)}px inside ${label(parent)}`,
						});
					}
				}
			}

			/* ── Air between the blocks of a screen ──────────────────────
			   Only inside the containers that hold a screen's own blocks:
			   two options in a group touch on purpose, two sections never
			   do. Pairs that sit side by side are skipped — that is a grid
			   row, and the gap between its columns is the grid's business. */
			if (isFlow(parent)) {
				for (let i = 1; i < kids.length; i++) {
					const above = kids[i - 1].rect;
					const below = kids[i].rect;
					const across = Math.min(above.right, below.right) - Math.max(above.left, below.left);

					if (across <= slack || below.top < above.bottom - slack) {
						continue;
					}

					if (
						look.exempt.some(
							(one) => kids[i - 1].el.matches(one) || kids[i].el.matches(one)
						)
					) {
						continue;
					}

					const gap = below.top - above.bottom;

					if (gap < look.minAir) {
						found.push({
							kind: 'air',
							where: `${trail(kids[i - 1].el)}  ↕  ${trail(kids[i].el)}`,
							detail: `${Math.round(gap)}px of space between them, and a block wants at least ${look.minAir}px`,
						});
					}
				}
			}
		}

		/* ── The second column ───────────────────────────────────────────
		   A screen that declares a rail says so in the markup, and the whole
		   point of it is that it is beside the settings. Underneath, it is
		   the interruption it was moved out of. Below the breakpoint the
		   opposite is the rule: two columns of 390px are neither. */
		const studio = host.querySelector('.diluxone-users-studio');
		const fields = studio?.querySelector(':scope > .diluxone-users-studio__fields') ?? null;
		const beside =
			studio?.querySelector(
				':scope > .diluxone-users-studio__aside, :scope > .diluxone-users-studio__preview'
			) ?? null;

		if (fields && beside) {
			const main = box(fields);
			const side = box(beside);
			const wide = window.innerWidth > look.railAt;

			if (wide && side.left < main.right - slack) {
				found.push({
					kind: 'rail',
					where: trail(beside),
					detail: `at ${window.innerWidth}px it should start after the settings end (${Math.round(main.right)}px) and starts at ${Math.round(side.left)}px`,
				});
			}

			if (wide && side.top > main.top + 4) {
				found.push({
					kind: 'rail',
					where: trail(beside),
					detail: `at ${window.innerWidth}px it should start level with the settings (${Math.round(main.top)}px) and starts at ${Math.round(side.top)}px`,
				});
			}

			if (!wide && side.top < main.bottom - slack) {
				found.push({
					kind: 'rail',
					where: trail(beside),
					detail: `at ${window.innerWidth}px it should be underneath the settings (${Math.round(main.bottom)}px) and starts at ${Math.round(side.top)}px`,
				});
			}
		}

		/* ── What the rail is made of ────────────────────────────────────
		   The rail has one shape, written down on
		   `diluxone_users_ui_aside_open()`: how this site stands today, then
		   what the tab is for, then where the rest of it lives. Nothing
		   stopped a screen from printing its own markup in there instead,
		   and a rail that is a different thing on every tab is one more
		   thing to read rather than the answer to a question.

		   Two halves. Anything in the column that is not one of the design
		   system's four blocks is a screen having its own opinion — that is
		   the half that catches a `<p>` somebody echoed. And the state, when
		   a screen reports one, goes first: it is the part that is about
		   this site rather than about the plugin, and underneath a paragraph
		   of manual it is the part nobody reaches.

		   Over all of it, on a tab that saves, the box that saves: it is not
		   part of what the rail says, so it is taken out before the state is
		   counted — and it has to be first, or it is not where every tab
		   keeps it. */
		const RAIL_BLOCKS = ['du-state', 'du-note', 'du-links', 'du-notice'];
		const aside = host.querySelector('.diluxone-users-studio__aside');

		if (aside) {
			const drawnBlocks = Array.from(aside.children).filter((child) =>
				drawn(child, getComputedStyle(child))
			);
			const save = drawnBlocks.findIndex((block) => block.classList.contains('du-save'));

			if (save > 0) {
				found.push({
					kind: 'shape',
					where: trail(drawnBlocks[save]),
					detail: `the box that saves is block ${save + 1} of ${drawnBlocks.length} in the rail, and it goes first`,
				});
			}

			const blocks = drawnBlocks.filter((block) => !block.classList.contains('du-save'));

			for (const block of blocks) {
				if (!RAIL_BLOCKS.some((one) => block.classList.contains(one))) {
					found.push({
						kind: 'shape',
						where: trail(block),
						detail: `is in the rail and is none of ${RAIL_BLOCKS.join(', ')}`,
					});
				}
			}

			const state = blocks.findIndex((block) => block.classList.contains('du-state'));

			if (state > 0) {
				found.push({
					kind: 'shape',
					where: trail(blocks[state]),
					detail: `how this site stands is block ${state + 1} of ${blocks.length} in the rail, and it goes first`,
				});
			}
		}

		return found;
	}, rules);
}

/** Whether the page as a whole can be scrolled sideways. Nothing may do that. */
export async function sidewaysScroll(page: Page): Promise<number> {
	return page.evaluate(
		() => document.documentElement.scrollWidth - document.documentElement.clientWidth
	);
}

/** How a list of findings reads when a test prints it. */
export function readFindings(findings: LayoutFinding[]): string {
	return findings.map((one) => `  [${one.kind}] ${one.where}\n      ${one.detail}`).join('\n');
}

/**
 * Measures one screen at every width that matters and complains about all of
 * it at once.
 *
 * Re-measuring rather than re-loading: the stylesheet is what answers a change
 * of width, and a reload per width would quadruple what the suite costs for
 * an answer that is already on the page. The page is handed in already
 * loaded, so a caller can open a panel or press something first and measure
 * what that produced.
 */
export async function expectSoundLayout(
	page: Page,
	rules: LayoutRules = ADMIN_RULES,
	widths: readonly number[] = WIDTHS
): Promise<void> {
	const wrong: string[] = [];

	for (const width of widths) {
		await page.setViewportSize({ width, height: 1000 });

		const findings = await layoutFindings(page, rules);
		const sideways = await sidewaysScroll(page);

		if (sideways > 1) {
			findings.push({
				kind: 'overflow',
				where: 'the page itself',
				detail: `scrolls ${sideways}px sideways`,
			});
		}

		if (findings.length > 0) {
			wrong.push(`at ${width}px:\n${readFindings(findings)}`);
		}
	}

	expect(wrong.join('\n\n'), `layout at ${page.url()}`).toBe('');
}
