<?php
/**
 * The admin's design system: where its colours come from, and its pieces.
 *
 * Every screen in here used to be a `form-table` with a grey paragraph under
 * each control. That is not a style, it is the absence of one: the title of
 * an option, the sentence explaining it and the title of the next option all
 * weigh the same, so nothing leads the eye and two options read as one. The
 * complaint was that it is not nice to look at. The cause was that nothing
 * had a rank.
 *
 * So: tokens, and pieces built out of them. Two rules govern both.
 *
 * The first is where the colour comes from. WordPress lets a person pick an
 * admin colour scheme — nine of them — and a plugin that paints itself blue
 * on somebody's midnight dashboard looks like a plugin that was pasted in.
 * The scheme is published (`$_wp_admin_css_colors`), so it is read and the
 * plugin follows it. A site that would rather fix its own says so through the
 * filter below, and nothing else changes.
 *
 * The second is restraint. Nothing here invents a second interface: the
 * screens keep WordPress's structure, its tabs, its buttons and its tables.
 * What is added is rank — a title that reads as a title, an option that reads
 * as one thing, and a state you can see without reading.
 *
 * There is a third rule now, and it is the one that took longest to admit.
 * Every piece a screen needs lives here. A screen that reaches for
 * `form-table` because this file has no number-with-a-word-after-it is a
 * screen that has started a second design system, and the admin ended up with
 * four of them: the label in the left column on one tab, the title above the
 * control on the next, and the same question asked in two shapes two clicks
 * apart. So when something is missing it is added here, once, and every screen
 * gets it — rather than each screen solving it again in its own file with its
 * own markup. Nothing below is drawn with a two-column table, and no screen
 * writes CSS of its own.
 *
 * And a fourth, which is the correction the third one needed. There is no one
 * width. The measure — where a line of prose and a column of options stop —
 * was being applied to the screen itself, so a grid of providers, an editor
 * with a list beside it and a table of rows were all squeezed into a width
 * chosen for reading, while the rest of the window sat empty beside them. The
 * measure belongs to what is read: the pieces below carry it themselves.
 * `diluxone_users_ui_wide_open()` is for the shapes that have columns of their
 * own, and `diluxone_users_ui_aside_open()` puts the room that was left over
 * to work as a rail. The two are not alternatives — a screen that is a table
 * still has things to say about itself, and the rail is where they go.
 *
 * Two pieces of the system live next door in `admin-state.php`, because what
 * they draw is a state and that file is where the four states and their words
 * are: `diluxone_users_summary_table()`, the strip a screen opens with, and
 * `diluxone_users_not_now()`, a control that does not apply yet with the
 * reason above it. The second one is the one exception to "what is said goes
 * in the rail": it is not about the screen, it is about the box under it, and
 * three inches away in the other column it would be about nothing.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * The colours of the admin colour scheme in use.
 *
 * Every scheme publishes four: two darks for the menu and two for the accent.
 * The third and fourth are the ones that carry meaning — WordPress uses them
 * for the current menu item and for what it wants noticed — so those are the
 * ones borrowed. "fresh", the default, is the fallback for a scheme that
 * publishes nothing, which some do.
 *
 * @return array{accent: string, accent_soft: string, ink: string}
 */
function diluxone_users_admin_scheme(): array {
	global $_wp_admin_css_colors;

	$name   = (string) get_user_option( 'admin_color' );
	$scheme = $_wp_admin_css_colors[ $name ] ?? null;
	$colors = is_object( $scheme ) && ! empty( $scheme->colors ) ? (array) $scheme->colors : array();

	// Third and fourth: the accent and its light companion. A scheme with
	// fewer is read as far as it goes and the rest is WordPress's own blue.
	$accent = (string) ( $colors[2] ?? '#2271b1' );
	$light  = (string) ( $colors[3] ?? '#72aee6' );

	/**
	 * Filters the colours the plugin's admin takes from the colour scheme.
	 *
	 * @since 1.0.0
	 *
	 * @param array{accent: string, accent_soft: string, ink: string} $colours
	 * @param string                                                  $name   The scheme in use.
	 */
	return (array) apply_filters(
		'diluxone_users_admin_scheme',
		array(
			'accent'      => $accent,
			'accent_soft' => $light,
			'ink'         => (string) ( $colors[0] ?? '#1d2327' ),
		),
		$name
	);
}

/**
 * Those colours, as the properties the stylesheet reads.
 *
 * Printed with the stylesheet rather than written into it: the scheme belongs
 * to the person looking, not to the site, and two administrators of the same
 * site can have picked different ones.
 */
function diluxone_users_admin_tokens(): string {
	// The scheme can come from a filter, and what it returns goes into a
	// stylesheet: a colour, or the default, and nothing that could close the
	// rule it is written into.
	$scheme = diluxone_users_admin_scheme();
	$safe   = static function ( $value, string $fallback ): string {
		$clean = diluxone_users_color_value( (string) $value );

		return '' === $clean ? $fallback : $clean;
	};

	$scheme = array(
		'accent'      => $safe( $scheme['accent'], '#2271b1' ),
		'accent_soft' => $safe( $scheme['accent_soft'], '#72aee6' ),
		'ink'         => $safe( $scheme['ink'], '#1d2327' ),
	);

	return ':root{'
		. '--du-accent:' . $scheme['accent'] . ';'
		. '--du-accent-light:' . $scheme['accent_soft'] . ';'
		// Washed down for the ground of something chosen, and a touch more
		// for its edge. Derived, so a scheme change carries them along.
		. '--du-accent-wash:color-mix(in srgb, ' . $scheme['accent'] . ' 8%, #fff);'
		. '--du-accent-edge:color-mix(in srgb, ' . $scheme['accent'] . ' 45%, #fff);'
		. '--du-ink:' . $scheme['ink'] . ';'
		. '}';
}

/**
 * The ground everything here stands on, opened by hand.
 *
 * Every number the pieces below are made of — the scale of space, the three
 * greys, the accent taken from the colour scheme — is declared on
 * `.diluxone-users-admin`, and the plugin's own screens wear that class
 * because the screen opener puts it there. The dashboard profile and the
 * sign-up an administrator performs are not the plugin's screens: a piece
 * drawn inside one of them gets its rules and none of its measurements, which
 * is a card with no padding and a help line in the wrong grey.
 *
 * So those screens open the ground themselves. It is a pair of functions and
 * not a `<div>` written into each of those files because the day that class
 * is renamed there should be one place that has to know.
 */
function diluxone_users_ui_ground_open(): void {
	echo '<div class="diluxone-users-admin">';
}

/** Closes it. */
function diluxone_users_ui_ground_close(): void {
	echo '</div>';
}

/**
 * A screen with a rail down its right-hand side.
 *
 * The measure — how wide a column of settings gets — was applied to the whole
 * screen, and that was two decisions wearing one number. A column of options
 * is read, so it stops at the measure; the screen is not read, it is the room
 * the column stands in, and on a wide window there were seven hundred pixels
 * of nothing to the right of everything. That is not spare room. It is the
 * column the screen was missing: the state of the thing being configured, the
 * sentence explaining it, the caveat that used to sit above the title where it
 * reads as news, and the way to whatever is related. Beside the settings all
 * of that is context; above and below them it is interruption.
 *
 * It is not a second two-column mechanism. A panel that declares a `preview`
 * already gets one from `diluxone_users_screen_panels()` — settings on the
 * left, what they do on the right — and a screen with two of those would have
 * two right-hand columns disagreeing about which is the right-hand column. So
 * this is that same component, with the other kind of second column in it, and
 * the rule is the obvious one: a panel has a preview or a rail, never both.
 *
 * What goes in the rail is handed to the closing call as a callable, rather
 * than being a third pair of open-and-close of its own, so a screen with
 * nothing to say beside itself draws no empty column: it is run into a buffer
 * and the column exists only if something came out of it. That
 * matters because most of what goes in a rail is conditional — a warning about
 * how this site is set up, a provider that is not configured yet — and a
 * bordered box with nothing in it is the thing this design system spent a whole
 * round taking off these screens.
 *
 * The settings themselves stay flat between the two calls: whatever a screen
 * prints after this goes in the main column, exactly as it did before.
 *
 * What goes in it has a shape, and it is always the same three parts in the
 * same order, because a rail that is a different thing on every tab is one
 * more thing to read rather than the answer to a question:
 *
 *   1. How this site stands today — `diluxone_users_ui_aside_state()`. Not
 *      what the tab can be set to: what it *is* set to, right now, in one
 *      sentence, with the pill that says whether that is good news.
 *   2. What the tab is for — `diluxone_users_ui_note()`, two or three lines.
 *      What it decides, and what it does not.
 *   3. Where the rest of it lives — `diluxone_users_ui_links()`.
 *
 * The first one is the part that was missing and the part that matters: the
 * rail reports on this site. It does not repeat the manual, and a sentence
 * that would read the same on a site that has never been configured belongs
 * in part two, where it is honest about being general.
 *
 * Above all three, on a tab that saves, goes the button that saves it — see
 * `diluxone_users_ui_save()`. It is not part of what the rail says; it is the
 * one thing the rail is for doing, and it goes where it is found first.
 */
function diluxone_users_ui_aside_open(): void {
	echo '<div class="diluxone-users-studio"><div class="diluxone-users-studio__fields">';
}

/**
 * Closes the main column and draws the rail beside it.
 *
 * @param callable $aside What goes in the rail. Nothing printed, no rail.
 */
function diluxone_users_ui_aside_close( callable $aside ): void {
	echo '</div>';

	ob_start();
	call_user_func( $aside );
	$rail = diluxone_users_ui_save_box() . trim( (string) ob_get_clean() );

	if ( '' !== $rail ) {
		// The rail stays in view while the settings scroll past it, which is
		// `position: sticky` in the stylesheet and nothing here.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the pieces that printed it escaped it.
		echo '<div class="diluxone-users-studio__aside">' . $rail . '</div>';
	}

	echo '</div>';
}

/**
 * Asks for the button that saves a form to be drawn in the column beside it.
 *
 * The button used to end the form, and a form of forty settings is two
 * windows of scrolling: the one control every visit ends on was the one that
 * could not be seen while anything was being changed. Beside the settings, in
 * the column that follows the page down, it is always in reach, and it is in
 * the same place on every screen — which is the other half of the point.
 *
 * It is queued rather than printed because the screen does not print the
 * column: `diluxone_users_ui_aside_close()` does, or the preview beside a
 * design tab, or `diluxone_users_screen_panels()` when a panel had nothing
 * else to put there. Whichever of them draws the second column takes what is
 * queued and puts it first. The button is tied to its form by the `form`
 * attribute and not by being inside it, so it can live outside the form — and
 * a tab with two forms queues two buttons, each with the words of what it
 * saves.
 *
 * @param string $form  The id of the form it sends.
 * @param string $label What it says; "Save changes" when nothing more precise
 *                      is true.
 * @param string $name  A name for the button, when the save tells presses
 *                      apart by it. Never `submit`: a control of that name
 *                      shadows the form's own method.
 */
function diluxone_users_ui_save( string $form, string $label = '', string $name = '' ): void {
	diluxone_users_ui_save_queue(
		array(
			'form'  => $form,
			'label' => '' !== $label ? $label : __( 'Save changes', 'diluxone-users' ),
			'name'  => $name,
		)
	);
}

/**
 * What is waiting to be drawn, and the way to take it.
 *
 * @param array{form: string, label: string, name: string}|null $button One more, or null to take them all.
 * @return list<array{form: string, label: string, name: string}> What was queued, when taken.
 */
function diluxone_users_ui_save_queue( ?array $button = null ): array {
	static $queue = array();

	if ( null !== $button ) {
		$queue[] = $button;

		return array();
	}

	$taken = $queue;
	$queue = array();

	return $taken;
}

/** Whether a button is still waiting for a column to be drawn in. */
function diluxone_users_ui_save_pending(): bool {
	$queue = diluxone_users_ui_save_queue();

	foreach ( $queue as $button ) {
		diluxone_users_ui_save_queue( $button );
	}

	return array() !== $queue;
}

/**
 * The box the queued buttons are drawn in, or nothing when there are none.
 *
 * One box, whatever is in it: a button the width of the column and a line
 * over it that says whether anything on the screen has changed since it was
 * opened. The line is the script's — without it nobody is watching the form,
 * and a sentence that cannot be kept true is not printed.
 */
function diluxone_users_ui_save_box(): string {
	$buttons = diluxone_users_ui_save_queue();

	if ( array() === $buttons ) {
		return '';
	}

	$html = sprintf(
		'<div class="du-save" data-diluxone-users-save><p class="du-save__state" hidden><span class="du-save__clean">%1$s</span><span class="du-save__dirty">%2$s</span></p>',
		esc_html__( 'Nothing changed yet', 'diluxone-users' ),
		esc_html__( 'There are unsaved changes', 'diluxone-users' )
	);

	foreach ( $buttons as $button ) {
		$html .= sprintf(
			'<button type="submit" class="button button-primary button-large du-save__button" form="%1$s"%2$s>%3$s</button>',
			esc_attr( $button['form'] ),
			'' !== $button['name'] ? ' name="' . esc_attr( $button['name'] ) . '" value="1"' : '',
			esc_html( $button['label'] )
		);
	}

	return $html . '</div>';
}

/**
 * How this site stands, at the head of the rail.
 *
 * The first thing a rail says is what is true here today. Every screen had a
 * way of answering that already and no two of them agreed: a summary table
 * with one row in it, a sentence in brackets after a title, a pill on its own
 * with nothing beside it saying what it was a pill about. Worse, most of what
 * they said was the manual — what the tab can be set to, which is the same
 * paragraph on a site where nothing has been configured and on one that has
 * been running for a year.
 *
 * This is the other question, and the useful one: *is* it on, what is it set
 * to, how many of them are there. One sentence in the present tense about
 * this site, and the pill that says whether that is good news. If the sentence
 * would read the same on every site that installs the plugin, it is not a
 * state — it is what the tab is for, and that is the note underneath.
 *
 * It goes first in the rail and nothing enforces that, because the rail is
 * printed in the order a screen prints it. Second and third are
 * `diluxone_users_ui_note()` and `diluxone_users_ui_links()`; the three of
 * them together are the shape described on `diluxone_users_ui_aside_open()`.
 *
 * @param string $line  What is true here, in one sentence, present tense.
 *                      Markup allowed: a state names things — a role, a
 *                      provider, an address — and those read as `<code>`.
 * @param string $state How that is doing, as `diluxone_users_state_pill()`
 *                      takes it: active, pending, off, unknown.
 * @param string $why   The half-dozen words that qualify the pill, if the
 *                      pill on its own would leave a question.
 */
function diluxone_users_ui_aside_state( string $line, string $state, string $why = '' ): void {
	// A pill with no sentence beside it is the thing this piece was written to
	// replace, so a screen with nothing to report draws no box at all.
	if ( '' === trim( $line ) ) {
		return;
	}

	printf(
		'<div class="du-state"><span class="du-state__head"><span class="du-state__now">%1$s</span>%2$s</span><p class="du-state__line">%3$s</p></div>',
		esc_html_x( 'Right now', 'the head of the rail: how this site stands today', 'diluxone-users' ),
		diluxone_users_state_pill( $state, $why ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		wp_kses_post( $line )
	);
}

/**
 * A block that takes all the width WordPress gives, and not the measure.
 *
 * The measure is for reading and for answering: a line of prose, a column of
 * options, a box you type into. Three things on these screens are neither, and
 * all three were being squeezed into it — the grid of sign-in providers, the
 * account area's two panels of list-and-editor, and the tables of the reports.
 * A grid squeezed to a reading width drops to two columns with white either
 * side of it; a table does the same thing and then cuts its own columns.
 *
 * So this is not an escape hatch for a screen that would like a bit more room.
 * It is for those three shapes — a grid of things, an editor with a list beside
 * it, a table of rows — and the test is whether the content has columns of its
 * own. Prose and form controls do not, and keep the measure even in here: a
 * text box 1600 pixels wide is not a better text box.
 */
function diluxone_users_ui_wide_open(): void {
	echo '<div class="du-wide">';
}

/** Closes it. */
function diluxone_users_ui_wide_close(): void {
	echo '</div>';
}

/**
 * A grid of cards, one thing per card.
 *
 * The overview opens with four of them — a number each — and the sign-in
 * providers screen is twelve, a network each. It was the same grid drawn from
 * two places: the overview had a helper and the providers screen printed the
 * same `<div>`s by hand because the helper escaped its icon as a class name
 * and its value as text, and a network needs a logo and a pill. So the two
 * differences became two keys, and there is one grid again.
 *
 * It has columns of its own, so it belongs inside `diluxone_users_ui_wide_open()`
 * on a screen whose other blocks are read at the measure.
 */
function diluxone_users_ui_cards_open(): void {
	echo '<div class="diluxone-users-cards">';
}

/** Closes it. */
function diluxone_users_ui_cards_close(): void {
	echo '</div>';
}

/**
 * One card of that grid.
 *
 * A mark, what the card is about, the one thing worth reading at a glance,
 * a sentence qualifying it, and the ways out. The glance is a number on the
 * overview and a state on the providers screen, which is why `value` and
 * `state` are two keys and not one: a number is text and a state is a pill,
 * and a piece that took either would have to guess which it had been given.
 *
 * The mark is a dashicon by name, or — for a network, whose logo is not in any
 * icon font — the drawing itself, which arrives as the plugin's own SVG and is
 * filtered down to the shapes an SVG is allowed to be made of.
 *
 * The ways out are text on the overview, where every card offers exactly one
 * and the card itself is the thing being read. A card that offers a choice is
 * a different matter: the twelve networks each lead somewhere to be set up,
 * somewhere to be switched off, and the two read as the same suggestion while
 * they were two links in a row. So a link can say what kind of action it is —
 * `primary` for the one thing that card is for, `secondary` for the other one,
 * `danger` for the one that takes something away — and it comes out as the
 * dashboard's own button in that weight. Not a button of this plugin's: the
 * admin already has three weights of button, they already follow the colour
 * scheme the person picked, and a fourth set drawn here would only have to be
 * kept in step with them.
 *
 * @param array<string, mixed> $card icon (a dashicon class) or mark (SVG), title,
 *                                   and optionally value, state, why, detail
 *                                   and links (each: url, label and optionally
 *                                   tone — primary, secondary or danger).
 */
function diluxone_users_ui_card( array $card ): void {
	$icon = (string) ( $card['icon'] ?? '' );
	$mark = (string) ( $card['mark'] ?? '' );

	echo '<div class="diluxone-users-card">';

	// No mark, no slot: an empty one is a forty-pixel coloured circle with
	// nothing in it, holding the title away from the edge for no reason.
	if ( '' !== $icon || '' !== $mark ) {
		printf(
			'<span class="diluxone-users-card__icon%1$s" aria-hidden="true">%2$s</span>',
			'' === $icon ? '' : ' dashicons ' . esc_attr( $icon ),
			wp_kses( $mark, diluxone_users_ui_svg_tags() )
		);
	}

	echo '<div class="diluxone-users-card__body">';

	printf( '<h3>%s</h3>', esc_html( (string) ( $card['title'] ?? '' ) ) );

	$state = (string) ( $card['state'] ?? '' );
	$value = '' !== $state
		? diluxone_users_state_pill( $state, (string) ( $card['why'] ?? '' ) )
		: esc_html( (string) ( $card['value'] ?? '' ) );

	if ( '' !== $value ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a pill escapes itself; a value was escaped above.
		echo '<b class="diluxone-users-card__value">' . $value . '</b>';
	}

	if ( ! empty( $card['detail'] ) ) {
		printf( '<p class="diluxone-users-card__detail">%s</p>', esc_html( (string) $card['detail'] ) );
	}

	$links = is_array( $card['links'] ?? null ) ? $card['links'] : array();

	if ( array() !== $links ) {
		echo '<p class="diluxone-users-card__links">';

		$weights = array(
			'primary'   => 'button button-primary',
			'secondary' => 'button button-secondary',
			'danger'    => 'button-link button-link-delete',
		);

		foreach ( $links as $link ) {
			$tone = (string) ( $link['tone'] ?? '' );

			printf(
				'<a class="diluxone-users-card__link%1$s" href="%2$s">%3$s%4$s</a>',
				isset( $weights[ $tone ] ) ? ' ' . esc_attr( $weights[ $tone ] ) : '',
				esc_url( (string) $link['url'] ),
				esc_html( (string) $link['label'] ),
				// The arrow is what a plain link has instead of an edge. A
				// button has the edge, and an arrow on it is one sign too many.
				isset( $weights[ $tone ] ) ? '' : ' &rarr;'
			);
		}

		echo '</p>';
	}

	echo '</div></div>';
}

/**
 * The tags an inline drawing is allowed to be made of.
 *
 * `wp_kses()` knows nothing about SVG, so a logo handed to it whole comes back
 * empty. This is the list the plugin's own marks are drawn with and nothing
 * else: no `<script>`, no `<foreignObject>`, no event attributes — so a mark
 * that came from a filter rather than from this plugin still cannot run
 * anything.
 *
 * @return array<string, array<string, bool>>
 */
function diluxone_users_ui_svg_tags(): array {
	return array(
		'svg'  => array(
			'class'       => true,
			'width'       => true,
			'height'      => true,
			'viewbox'     => true,
			'fill'        => true,
			'aria-hidden' => true,
			'focusable'   => true,
		),
		'g'    => array( 'fill' => true ),
		'path' => array(
			'd'         => true,
			'fill'      => true,
			'fill-rule' => true,
		),
	);
}

/**
 * One option of a group, drawn as a card.
 *
 * The card is the whole point. A radio with its title beside it and its
 * explanation in a grey paragraph underneath belongs to nothing: the eye
 * cannot tell whether that paragraph explains the option above it or the one
 * below, and on the registration screen it genuinely could not — four tick
 * boxes and four sentences, alternating, none of them joined. Inside a card
 * with a border, an option is one thing, and what is said about it is said
 * inside it.
 *
 * @param array<string, mixed> $choice name, value, title and optionally type,
 *                                     help, checked, disabled, state, note,
 *                                     piece, data and id.
 */
function diluxone_users_ui_choice( array $choice ): void {
	$type     = ( $choice['type'] ?? 'radio' ) === 'checkbox' ? 'checkbox' : 'radio';
	$checked  = ! empty( $choice['checked'] );
	$disabled = ! empty( $choice['disabled'] );
	$classes  = array( 'du-choice' );

	if ( $checked ) {
		$classes[] = 'is-on';
	}

	if ( $disabled ) {
		$classes[] = 'is-locked';
	}
	?>
	<label class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
		<input
			type="<?php echo esc_attr( $type ); ?>"
			name="<?php echo esc_attr( (string) $choice['name'] ); ?>"
			value="<?php echo esc_attr( (string) $choice['value'] ); ?>"
			<?php echo isset( $choice['id'] ) ? 'id="' . esc_attr( (string) $choice['id'] ) . '"' : ''; ?>
			<?php echo isset( $choice['piece'] ) ? 'data-diluxone-users-piece="' . esc_attr( (string) $choice['piece'] ) . '"' : ''; ?>
			<?php echo diluxone_users_ui_data( (array) ( $choice['data'] ?? array() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
			<?php checked( $checked ); ?>
			<?php disabled( $disabled ); ?>>

		<span class="du-choice__text">
			<span class="du-choice__title">
				<?php echo esc_html( (string) $choice['title'] ); ?>
				<?php if ( ! empty( $choice['state'] ) ) : ?>
					<?php echo diluxone_users_state_pill( (string) $choice['state'], (string) ( $choice['note'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
				<?php endif; ?>
			</span>

			<?php if ( ! empty( $choice['help'] ) ) : ?>
				<span class="du-choice__help"><?php echo wp_kses_post( (string) $choice['help'] ); ?></span>
			<?php endif; ?>
		</span>
	</label>
	<?php
}

/**
 * A group of them, and whatever hangs off the one that is chosen.
 *
 * A choice carries its own `children`: a callable printed inside that card
 * and shown only while it is on. It goes on the choice and not in a list
 * keyed by value because four tick boxes all worth "1" cannot be told apart
 * by value — and because a setting that exists only because of one option
 * belongs to it, not to the group.
 *
 * Asking for the registration page in a row of its own, three rows below the
 * box that turns the form on, is how a screen ends up explaining itself.
 *
 * `$needs_one` is for a group of tick boxes where none ticked is not an
 * answer: the ways into an account, the second factors offered. It is said
 * beside the group and not at the top of the screen, because the group is
 * what has to change, and the sentence is written here — in the page, in the
 * site's language — rather than handed to the browser in an attribute. The
 * script only shows it. What actually refuses the save is
 * `diluxone_users_ui_needs_one()` on the other side; this is the part that
 * stops somebody sending a form they did not mean to.
 *
 * `$only_while` is what that rule needs to stop being a nuisance, and both
 * groups that use it needed it. None of the second factors ticked is fine
 * while the second step is off, and no door open is the whole of "nobody can
 * register" — so the rule hangs off another answer on the same screen. Left
 * out, the two screens each solved it their own way: one drew the sentence
 * only when the saved answer allowed it, which is a page that goes stale the
 * moment somebody changes that answer without saving. Named here, the browser
 * reads the answer as it is now and the two screens ask in one voice.
 *
 * @param array<int, array<string, mixed>> $choices
 * @param string                           $needs_one  What to say when none of them is ticked, or '' when none is an answer.
 * @param array<string, mixed>             $only_while name of the control the rule hangs off and `is`, the values that arm it.
 */
function diluxone_users_ui_choices( array $choices, string $needs_one = '', array $only_while = array() ): void {
	if ( '' !== $needs_one ) {
		$armed = array() === $only_while
			? ''
			: diluxone_users_ui_data(
				array(
					'while'    => (string) $only_while['name'],
					'while-is' => implode( ' ', array_map( 'strval', (array) $only_while['is'] ) ),
				)
			);

		printf(
			'<div class="du-needs-one" data-diluxone-users-atleast-one%s>',
			$armed // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		);
	}

	echo '<div class="du-choices">';

	foreach ( $choices as $choice ) {
		$children = $choice['children'] ?? null;

		if ( ! is_callable( $children ) ) {
			diluxone_users_ui_choice( $choice );
			continue;
		}

		/*
		 * The card and its children are wrapped together so the indent reads
		 * as "this belongs to that", and the wrapper carries whether it is
		 * open: with the script off everything is visible and the server
		 * still reads whatever was submitted.
		 */
		printf(
			'<div class="du-choice-group %s" data-diluxone-users-group>',
			empty( $choice['checked'] ) ? '' : 'is-open'
		);

		diluxone_users_ui_choice( $choice );

		echo '<div class="du-choice__children">';
		call_user_func( $children );
		echo '</div>';

		echo '</div>';
	}

	echo '</div>';

	if ( '' !== $needs_one ) {
		/*
		 * Hidden until it is true, and it is the browser that decides when:
		 * drawn visible it would be an accusation made before anything was
		 * done. With the script off it never shows and the server says the
		 * same thing after the press, which is the same answer a beat later.
		 */
		printf(
			'<p class="du-needs-one__said" data-diluxone-users-atleast-one-said hidden>%s</p></div>',
			esc_html( $needs_one )
		);
	}
}

/**
 * A heading inside a tab, for the group of settings under it.
 *
 * With a line under it and air above, because the thing it is separating is
 * exactly what nothing was separating before.
 */
function diluxone_users_ui_section( string $title, string $intro = '' ): void {
	printf( '<h3 class="du-section">%s</h3>', esc_html( $title ) );

	if ( '' !== $intro ) {
		printf( '<p class="du-section__intro">%s</p>', esc_html( $intro ) );
	}
}

/**
 * A line that belongs to a control and is not a choice: a number, a page, a
 * colour. Label above, control, help under it.
 *
 * It exists so a screen can stop using `form-table` where the two-column
 * shape does not help — a group of four numbers reads better in a row than
 * as four rows of a table.
 */
function diluxone_users_ui_field_open( string $label, string $target = '' ): void {
	echo '<div class="du-field">';

	if ( '' !== $label ) {
		printf(
			'<%1$s class="du-field__label"%2$s>%3$s</%1$s>',
			'' === $target ? 'span' : 'label',
			'' === $target ? '' : ' for="' . esc_attr( $target ) . '"',
			esc_html( $label )
		);
	}
}

/** Closes it, with the help under the control. */
function diluxone_users_ui_field_close( string $help = '' ): void {
	if ( '' !== $help ) {
		printf( '<p class="du-field__help">%s</p>', wp_kses_post( $help ) );
	}

	echo '</div>';
}

/**
 * Several of them side by side.
 *
 * Two numbers that answer one question — how long a session lasts with
 * "remember me" and how long without it — are one thing with two boxes, and
 * stacking them makes the second read as a new subject. The row wraps on a
 * narrow window, so nothing is ever pushed off the side.
 */
function diluxone_users_ui_fields_open(): void {
	echo '<div class="du-fields">';
}

/** Closes the row. */
function diluxone_users_ui_fields_close(): void {
	echo '</div>';
}

/**
 * The box of a number, with whatever word comes after it.
 *
 * Separated out because both shapes below need it and because the word after
 * the box is not decoration: "30" is not a setting, "30 days" is. Every screen
 * that wanted one used to write the input and then drop a bare `esc_html_e()`
 * next to it, which is why the spacing was different on each of them.
 *
 * @param array<string, mixed> $field name, value and optionally id, min, max, step.
 */
function diluxone_users_ui_number_box( array $field ): string {
	$name = (string) $field['name'];

	return sprintf(
		'<input type="number" id="%1$s" name="%2$s" class="small-text" value="%3$s"%4$s%5$s%6$s>',
		esc_attr( (string) ( $field['id'] ?? $name ) ),
		esc_attr( $name ),
		esc_attr( (string) ( $field['value'] ?? '' ) ),
		isset( $field['min'] ) ? ' min="' . esc_attr( (string) $field['min'] ) . '"' : '',
		isset( $field['max'] ) ? ' max="' . esc_attr( (string) $field['max'] ) . '"' : '',
		isset( $field['step'] ) ? ' step="' . esc_attr( (string) $field['step'] ) . '"' : ''
	);
}

/**
 * A number, with its title above it and its unit beside it.
 *
 * "For how long: [30] days — 0 to ask every time". The unit belongs on the
 * line with the box and the sentence belongs under it, which is the one thing
 * a two-column table could never do: there the unit either went in the label
 * column, where it reads as part of the question, or on its own line under
 * the box, where it reads as an answer to something else.
 *
 * @param array<string, mixed> $field label, name, value and optionally id,
 *                                    suffix, help, min, max, step.
 */
function diluxone_users_ui_number( array $field ): void {
	diluxone_users_ui_field_open(
		(string) ( $field['label'] ?? '' ),
		(string) ( $field['id'] ?? $field['name'] )
	);

	echo '<span class="du-number">';
	echo diluxone_users_ui_number_box( $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.

	if ( ! empty( $field['suffix'] ) ) {
		printf( '<span class="du-number__unit">%s</span>', esc_html( (string) $field['suffix'] ) );
	}

	echo '</span>';

	diluxone_users_ui_field_close( (string) ( $field['help'] ?? '' ) );
}

/**
 * Two numbers that are the ends of one measure: "3 to 30 characters".
 *
 * Not two fields in a row, because it is not two settings. A shortest and a
 * longest name are one rule with two ends, and a person reading it reads the
 * sentence, not the boxes. Which is also why the word between them is a
 * parameter: "to" in one place is "and" or "up to" in another, and a
 * translator needs the whole line to work with.
 *
 * @param array<string, mixed> $field label, from, to (each an array as
 *                                    diluxone_users_ui_number_box() takes)
 *                                    and optionally between, suffix, help.
 */
function diluxone_users_ui_range( array $field ): void {
	/** @var array<string, mixed> $from */
	$from = (array) $field['from'];
	/** @var array<string, mixed> $to */
	$to = (array) $field['to'];

	diluxone_users_ui_field_open(
		(string) ( $field['label'] ?? '' ),
		(string) ( $from['id'] ?? $from['name'] )
	);

	echo '<span class="du-number">';
	echo diluxone_users_ui_number_box( $from ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.

	if ( ! empty( $field['between'] ) ) {
		printf( '<span class="du-number__unit">%s</span>', esc_html( (string) $field['between'] ) );
	}

	echo diluxone_users_ui_number_box( $to ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.

	if ( ! empty( $field['suffix'] ) ) {
		printf( '<span class="du-number__unit">%s</span>', esc_html( (string) $field['suffix'] ) );
	}

	echo '</span>';

	diluxone_users_ui_field_close( (string) ( $field['help'] ?? '' ) );
}

/**
 * The data attributes a piece carries, as markup.
 *
 * Every attribute the admin's script reads is namespaced the same way, so
 * what a caller passes is the part that differs — `array( 'preview' => 'cols' )`
 * — and the prefix is written once, here. It matters because the pieces stopped
 * taking them at all for a while: a screen that needed the preview to hear
 * about a control could not use a piece for it, so it wrote the control by
 * hand, and that is how a screen starts a design system of its own.
 *
 * @param array<string, string> $data Attribute names without their prefix.
 */
function diluxone_users_ui_data( array $data ): string {
	$out = '';

	foreach ( $data as $key => $value ) {
		$out .= sprintf(
			' data-diluxone-users-%1$s="%2$s"',
			esc_attr( (string) $key ),
			esc_attr( (string) $value )
		);
	}

	return $out;
}

/**
 * A line of text, with its title above it and its help under it.
 *
 * This is the piece whose absence did the most damage. There was a number and
 * there was a pair of numbers and there was nothing at all for a box you type
 * a sentence into — so every screen that wanted one wrote the `<input>`
 * itself, and picked a width on the way past. `regular-text` on the name of a
 * section, `large-text` on the line under it, `regular-text` again on the text
 * of a button: three widths for the same kind of answer, on screens two clicks
 * apart, and none of them chosen on purpose. One measure now, and it is the
 * full width of the field, which is the measure everything else on these
 * screens already had.
 *
 * The placeholder is not decoration. On most of these boxes empty is a real
 * answer — it means "say whatever the plugin says" — and the placeholder is
 * where that sentence is shown, so the box is never a blank asking to be
 * guessed at and emptying it is never a step into the dark.
 *
 * @param array<string, mixed> $field label, name, value and optionally id,
 *                                    placeholder, help, type, code, required,
 *                                    readonly, disabled, data.
 */
function diluxone_users_ui_text( array $field ): void {
	$name = (string) $field['name'];
	$id   = (string) ( $field['id'] ?? $name );
	$type = (string) ( $field['type'] ?? 'text' );

	diluxone_users_ui_field_open( (string) ( $field['label'] ?? '' ), $id );

	printf(
		'<input type="%1$s" id="%2$s" name="%3$s" class="large-text%4$s" value="%5$s"%6$s%7$s%8$s%9$s%10$s>',
		esc_attr( in_array( $type, array( 'text', 'email', 'url', 'password' ), true ) ? $type : 'text' ),
		esc_attr( $id ),
		esc_attr( $name ),
		empty( $field['code'] ) ? '' : ' code',
		esc_attr( (string) ( $field['value'] ?? '' ) ),
		empty( $field['placeholder'] ) ? '' : ' placeholder="' . esc_attr( (string) $field['placeholder'] ) . '"',
		empty( $field['required'] ) ? '' : ' required',
		empty( $field['readonly'] ) ? '' : ' readonly',
		disabled( ! empty( $field['disabled'] ), true, false ),
		diluxone_users_ui_data( (array) ( $field['data'] ?? array() ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	);

	diluxone_users_ui_field_close( (string) ( $field['help'] ?? '' ) );
}

/**
 * The same, for an answer that comes in more than one line.
 *
 * A heading written as two lines, a list of addresses, the body of an e-mail:
 * all of them typed into a one-line box is how a site ends up copying a
 * template to get a line break into a title. `code` is for the ones that are
 * read by a machine as much as by a person — addresses, ranges, a message with
 * `{link}` in it — where a monospaced face is the difference between seeing a
 * stray space and not.
 *
 * @param array<string, mixed> $field label, name, value and optionally id,
 *                                    rows, placeholder, help, code, required,
 *                                    readonly, disabled, data.
 */
function diluxone_users_ui_textarea( array $field ): void {
	$name = (string) $field['name'];
	$id   = (string) ( $field['id'] ?? $name );

	diluxone_users_ui_field_open( (string) ( $field['label'] ?? '' ), $id );

	printf(
		'<textarea id="%1$s" name="%2$s" rows="%3$d" class="large-text%4$s"%5$s%6$s%7$s%8$s%9$s>%10$s</textarea>',
		esc_attr( $id ),
		esc_attr( $name ),
		(int) ( $field['rows'] ?? 4 ),
		empty( $field['code'] ) ? '' : ' code',
		empty( $field['placeholder'] ) ? '' : ' placeholder="' . esc_attr( (string) $field['placeholder'] ) . '"',
		empty( $field['required'] ) ? '' : ' required',
		empty( $field['readonly'] ) ? '' : ' readonly',
		disabled( ! empty( $field['disabled'] ), true, false ),
		diluxone_users_ui_data( (array) ( $field['data'] ?? array() ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		esc_textarea( (string) ( $field['value'] ?? '' ) )
	);

	diluxone_users_ui_field_close( (string) ( $field['help'] ?? '' ) );
}

/**
 * A sentence the site is allowed to rewrite, with the plugin's own beneath it.
 *
 * The wording screens are a column of these: the heading of the sign-in form,
 * the line under it, what the panel says down the side. Every one of them is
 * an option that is empty out of the box, and empty means "say whatever the
 * plugin says" — so the placeholder is the sentence the box is about to
 * replace. A box that showed a blank would be asking somebody to guess what
 * they were overwriting, and emptying one again would be a step into the dark
 * instead of the way back.
 *
 * It reads the option itself because that is the whole of what these screens
 * do, and it lived on the sign-in screen until the design screen started
 * calling it across — one screen reaching into another for a control is the
 * long way round to two of them.
 *
 * @param string $key      The option, which is also the field's name and id.
 * @param string $label    The question.
 * @param string $fallback What the plugin says when the box is empty.
 * @param string $help     A line under it, or '' for none.
 */
function diluxone_users_ui_words( string $key, string $label, string $fallback, string $help = '' ): void {
	diluxone_users_ui_text(
		array(
			'label'       => $label,
			'name'        => $key,
			'value'       => (string) diluxone_users_option( $key ),
			'placeholder' => $fallback,
			'help'        => $help,
		)
	);
}

/**
 * The same, for the sentences that come in more than one line.
 *
 * A heading on a panel is written as deliberate lines and a list of advantages
 * is written as lines, and both of them typed into a one-line box is how a site
 * ends up copying a template to get a line break into a title. `$code` is for
 * the ones read by a machine as well as by a person — the body of an e-mail
 * with `{link}` in it, a line of terms with a tag in it — where a monospaced
 * face is the difference between seeing a stray space and not.
 *
 * @param string $key         The option, which is also the field's name and id.
 * @param string $label       The question.
 * @param string $placeholder What the plugin says when the box is empty.
 * @param string $help        A line under it, or '' for none.
 * @param int    $rows        How tall the box starts.
 * @param bool   $code        Whether what goes in it is read by a machine too.
 */
function diluxone_users_ui_words_area( string $key, string $label, string $placeholder, string $help = '', int $rows = 3, bool $code = false ): void {
	diluxone_users_ui_textarea(
		array(
			'label'       => $label,
			'name'        => $key,
			'value'       => (string) diluxone_users_option( $key ),
			'placeholder' => $placeholder,
			'help'        => $help,
			'rows'        => $rows,
			'code'        => $code,
		)
	);
}

/**
 * A block folded away until somebody wants it.
 *
 * Some settings are not a question the screen is asking; they are the answer
 * to “what if the guess was wrong”. Which colour of a theme’s palette plays
 * which part is one: a theme that names its colours the way WordPress suggests
 * is mapped without anybody being asked, and the row of dropdowns that exists
 * for the themes that number theirs is, on every other site, seven questions
 * about something already right. Drawn open it is read as work to do. Drawn
 * behind a heading that says what is inside it, it is read as a reassurance
 * that the work CAN be done.
 *
 * `<details>` and not a script, because the shape has to survive the script
 * being off and because the browser already knows how to do this. The inside
 * is indented with the same piece a choice’s children use — it is the same
 * relationship, said the same way.
 *
 * @param array<string, mixed> $block  label, and optionally help (the line
 *                                     under the heading, which is what a
 *                                     reader decides on without opening it),
 *                                     open and data.
 * @param callable             $inside What is folded away.
 */
function diluxone_users_ui_fold( array $block, callable $inside ): void {
	/*
	 * Its own classes, and that is the fix rather than the tidying.
	 *
	 * It was built out of `du-field` and `du-section` — the class for a
	 * control and the class for a heading — with the boxes inside wearing the
	 * class for what hangs off a tick box. None of the three was written for
	 * this, so none of them styled it: every row came out as a page heading
	 * with a rule above it and the browser's own grey triangle beside it, one
	 * after another down the screen, on the two tabs that use this. It did not
	 * look like the plugin because it was not using the plugin's pieces.
	 */
	printf(
		'<details class="du-fold"%1$s%2$s><summary class="du-fold__head"><span class="du-fold__text"><span class="du-fold__title">%3$s</span>%4$s</span></summary><div class="du-fold__body">',
		empty( $block['open'] ) ? '' : ' open',
		diluxone_users_ui_data( (array) ( $block['data'] ?? array() ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		esc_html( (string) ( $block['label'] ?? '' ) ),
		empty( $block['help'] )
			? ''
			: '<span class="du-fold__help">' . esc_html( (string) $block['help'] ) . '</span>'
	);

	call_user_func( $inside );

	echo '</div></details>';
}

/**
 * A text the plugin ships, that this site may rewrite, folded away until
 * somebody wants it.
 *
 * Two screens grew this shape within a week of each other — the wording of
 * every e-mail, and the sentences the sign-in form says when something goes
 * wrong — and both of them built it out of `du-field`, `du-section` and the
 * indent, with a comment admitting the design system had no piece for it. Two
 * copies of a shape is how the two of them start drifting: one says "the box",
 * the other "the two boxes", one is foldable and the other grows a heading,
 * and a year later they are different screens that happen to look alike.
 *
 * So it is one piece, and what it knows is the whole of what makes these
 * texts different from an ordinary setting: that there is always something
 * there. The plugin's own words are the starting point, a rewrite belongs to a
 * language rather than to the site, and putting the plugin's words back has to
 * be a thing you can ask for — emptying the boxes says "say nothing", which is
 * not the same wish.
 *
 * The line under the title is therefore the one sentence a reader needs before
 * opening it: whether what goes out is the plugin's or this site's, and in
 * which language this site wrote it. It is said here rather than by the two
 * callers so that it cannot be said two ways.
 *
 * The boxes themselves are the caller's — one text and one area for an e-mail,
 * a single area for a sentence — because that is the part that genuinely
 * differs. They are printed inside the fold by the callable, the same way a
 * rail is.
 *
 * @param array<string, mixed> $block label, help (what the text is for, after
 *                                    the title), language (the name of the
 *                                    language this site rewrote it in, or ''
 *                                    when it did not), revert (the name of the
 *                                    checkbox that brings the plugin's words
 *                                    back, or '' for none) and name (what a
 *                                    test calls this block, or '' for none).
 * @param callable             $boxes What is typed into.
 */
function diluxone_users_ui_rewritable( array $block, callable $boxes ): void {
	$language = (string) ( $block['language'] ?? '' );
	$revert   = (string) ( $block['revert'] ?? '' );
	$name     = (string) ( $block['name'] ?? '' );

	$said = '' === $language
		? __( 'As the plugin says it.', 'diluxone-users' )
		: sprintf(
			/* translators: %s: the name of a language, e.g. Spanish (Argentina). */
			__( 'Written by this site, in %s.', 'diluxone-users' ),
			$language
		);

	diluxone_users_ui_fold(
		array(
			'label' => (string) ( $block['label'] ?? '' ),
			'help'  => $said . ' ' . (string) ( $block['help'] ?? '' ),
			'data'  => '' === $name ? array() : array( 'rewritable' => $name ),
		),
		static function () use ( $boxes, $language, $revert ): void {
			call_user_func( $boxes );

			// Only offered where there is something to undo: on a text nobody
			// has rewritten it would be a way of asking for what is already
			// true.
			if ( '' !== $language && '' !== $revert ) {
				diluxone_users_ui_choice(
					array(
						'type'  => 'checkbox',
						'name'  => $revert,
						'value' => '1',
						'title' => __( 'Put the plugin’s own words back', 'diluxone-users' ),
						'help'  => __( 'Tick it and save: whatever is written above is dropped and this goes back to what the plugin says in this language — which is also what an update of the plugin can go on improving.', 'diluxone-users' ),
					)
				);
			}
		}
	);
}

/**
 * A question answered from a list, when the list is too long to show.
 *
 * `diluxone_users_ui_choices()` is what an answer worth explaining gets, and
 * `diluxone_users_ui_inline_choices()` is what a short one gets. Neither of
 * them survives twelve answers, or two hundred countries, and that is what
 * this is for — not a third opinion about how a question looks, but the shape
 * the other two cannot take.
 *
 * `disabled` needs the answer sent all the same when a screen is showing a
 * setting it will not let anybody change, so it takes a `hidden` companion
 * rather than leaving the save to read an absence as a decision.
 *
 * @param array<string, mixed> $field label, name, value, options (value =>
 *                                    label) and optionally id, help, disabled,
 *                                    keep, data.
 */
function diluxone_users_ui_select( array $field ): void {
	$name = (string) $field['name'];
	$id   = (string) ( $field['id'] ?? $name );
	$now  = (string) ( $field['value'] ?? '' );

	diluxone_users_ui_field_open( (string) ( $field['label'] ?? '' ), $id );

	printf(
		'<select id="%1$s" name="%2$s"%3$s%4$s>',
		esc_attr( $id ),
		esc_attr( $name ),
		disabled( ! empty( $field['disabled'] ), true, false ),
		diluxone_users_ui_data( (array) ( $field['data'] ?? array() ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	);

	/** @var array<string, string> $options */
	$options = (array) ( $field['options'] ?? array() );

	foreach ( $options as $value => $label ) {
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( (string) $value ),
			selected( $now, (string) $value, false ),
			esc_html( (string) $label )
		);
	}

	echo '</select>';

	if ( ! empty( $field['disabled'] ) && ! empty( $field['keep'] ) ) {
		printf(
			'<input type="hidden" name="%1$s" value="%2$s">',
			esc_attr( $name ),
			esc_attr( $now )
		);
	}

	diluxone_users_ui_field_close( (string) ( $field['help'] ?? '' ) );
}

/**
 * A colour that is allowed not to be one.
 *
 * A colour picker cannot say "empty". Once it has been touched it has a value
 * and there is no way back to "follow the accent", which is the answer these
 * settings ship with and the answer a site should keep: a second colour set
 * once and forgotten drifts away from the first the day the accent changes.
 *
 * So the way back is a box, and the box is what decides — the picker beside it
 * is switched off while it is clear, so nothing is posted and the accent wins.
 * The pair was written out twice on the appearance screen, the same six lines
 * with two names changed, which is the small version of the same problem as
 * the missing text box: a shape that exists twice is a shape that will exist
 * differently.
 *
 * @param array<string, mixed> $field label, name, value, fallback and
 *                                    optionally id, own_name, own_label, help.
 */
function diluxone_users_ui_color( array $field ): void {
	$name = (string) $field['name'];
	$id   = (string) ( $field['id'] ?? $name );
	$now  = (string) ( $field['value'] ?? '' );
	$own  = '' !== $now;

	diluxone_users_ui_field_open( (string) ( $field['label'] ?? '' ), $id );

	diluxone_users_ui_choice(
		array(
			'type'    => 'checkbox',
			'name'    => (string) ( $field['own_name'] ?? $name . '_own' ),
			'value'   => '1',
			'checked' => $own,
			'title'   => (string) ( $field['own_label'] ?? __( 'A colour of its own', 'diluxone-users' ) ),
			'data'    => array( 'toggle' => '#' . $id ),
		)
	);

	printf(
		'<input type="color" id="%1$s" name="%2$s" value="%3$s"%4$s>',
		esc_attr( $id ),
		esc_attr( $name ),
		esc_attr( $own ? $now : (string) ( $field['fallback'] ?? '#ffffff' ) ),
		disabled( ! $own, true, false )
	);

	diluxone_users_ui_field_close( (string) ( $field['help'] ?? '' ) );
}

/**
 * A short question answered in a line: the title above, the answers in a row.
 *
 * The card group is for options that need explaining — a title and two lines
 * of help each. Some questions do not have that in them: whether spaces become
 * dashes or are refused is two words against two words, and four cards for it
 * gives it the weight of the thing it is a detail of. So: a row, plain radios,
 * and the explanation once underneath for the pair rather than once inside
 * each of them.
 *
 * It is the same shape `diluxone_users_scope_control()` draws for "everybody /
 * only some roles", on purpose — that question is this question, and a person
 * meeting it on the second screen should not have to work out that it is.
 *
 * @param string                           $label   The question, above the row.
 * @param array<int, array<string, mixed>> $choices Each: name, value, title and optionally type, checked, disabled.
 * @param string                           $help    A line under the row, or '' for none.
 */
function diluxone_users_ui_inline_choices( string $label, array $choices, string $help = '' ): void {
	echo '<div class="du-field du-inline">';

	if ( '' !== $label ) {
		printf( '<span class="du-field__label">%s</span>', esc_html( $label ) );
	}

	echo '<div class="du-inline__row">';

	foreach ( $choices as $choice ) {
		?>
		<label class="du-inline__choice">
			<input
				type="<?php echo ( 'checkbox' === ( $choice['type'] ?? 'radio' ) ) ? 'checkbox' : 'radio'; ?>"
				name="<?php echo esc_attr( (string) $choice['name'] ); ?>"
				value="<?php echo esc_attr( (string) $choice['value'] ); ?>"
				<?php echo isset( $choice['id'] ) ? 'id="' . esc_attr( (string) $choice['id'] ) . '"' : ''; ?>
				<?php checked( ! empty( $choice['checked'] ) ); ?>
				<?php disabled( ! empty( $choice['disabled'] ) ); ?>>
			<?php echo esc_html( (string) $choice['title'] ); ?>
		</label>
		<?php
	}

	echo '</div>';

	diluxone_users_ui_field_close( $help );
}

/**
 * A list of things to do, in the order they are done.
 *
 * The guide to each provider's console is a dozen clicks, and a dozen clicks
 * written as a paragraph is a paragraph nobody can find their place in again
 * after switching to the other tab to do one of them. Numbered, each one is a
 * place: "I am on four".
 *
 * There is a numbered list on the front screen already and it is not this one
 * — that one is the state of the site, with ticks on what is done, and it
 * takes its numbers from a counter so it can replace them. Two lists, two
 * jobs; what they must not be is two lists that look different for no reason,
 * which is what they were while this one had no piece and went out as a bare
 * `<ol>`.
 *
 * @param array<int, string> $steps In order. Escaped here.
 */
function diluxone_users_ui_steps( array $steps ): void {
	echo '<ol class="du-steps">';

	foreach ( $steps as $step ) {
		printf( '<li>%s</li>', esc_html( (string) $step ) );
	}

	echo '</ol>';
}

/**
 * Something the screen has to say that is not a setting.
 *
 * "Taken names" on the public-name screen is a paragraph: the site always
 * checks them and there is nothing to choose. As a row of a two-column table
 * it came out as a title beside an empty cell and a cell with a sentence in
 * it — a box with nothing in it and a box with no title — and that empty
 * label cell is one of the ghost boxes on that screen.
 *
 * Here it is what it is: a small title and what it says.
 *
 * The body takes a list as readily as a sentence, and that is not a
 * convenience. It used to be one paragraph and nothing else, so a screen with
 * three things to say said them with `<br>` between — which is a line break
 * pretending to be a paragraph, reads as one block at any size, and cannot
 * hold a list at all without inventing markup that is not allowed inside a
 * `<p>`. Hand it lines and it writes the paragraphs.
 *
 * It takes a state as well, and that is what a rail is mostly made of. "Is
 * this on?" was being answered by a four-column summary table with one row in
 * it, which is a table drawn for the sake of a pill; beside the settings there
 * is no room for four columns and no need for them either. A title, the pill,
 * and the sentence that qualifies it is the whole of what that strip ever
 * said.
 *
 * @param string                           $title What it is about.
 * @param string|array<int|string, string> $body  What there is to say, as one
 *                                                paragraph or as several.
 *                                                Markup allowed: these
 *                                                sentences name the columns
 *                                                they are about, in `<code>`.
 * @param string                           $state How the thing is doing, as
 *                                                `diluxone_users_state_pill()`
 *                                                takes it, or '' for a note
 *                                                that is not about a state.
 * @param string                           $why   The reason beside the pill.
 * @param string                           $word  What to call that state here,
 *                                                when the title is a question
 *                                                rather than a switch — see
 *                                                `diluxone_users_state_pill()`.
 */
function diluxone_users_ui_note( string $title, $body, string $state = '', string $why = '', string $word = '' ): void {
	echo '<div class="du-note">';

	if ( '' !== $title || '' !== $state ) {
		printf(
			'<span class="du-note__title">%1$s%2$s</span>',
			esc_html( $title ),
			'' === $state ? '' : diluxone_users_state_pill( $state, $why, $word ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		);
	}

	foreach ( diluxone_users_ui_paragraphs( $body ) as $paragraph ) {
		printf( '<p class="du-note__body">%s</p>', wp_kses_post( $paragraph ) );
	}

	echo '</div>';
}

/**
 * The way to the things this screen is not about.
 *
 * Half of what a setting needs in order to make sense lives on another tab.
 * The second factor is decided here and the e-mail that carries the code is
 * written two screens away; the sign-in providers are configured here and how
 * their buttons look is somewhere else. Said in a paragraph in the middle of
 * the settings, that is a sentence that interrupts what somebody is doing to
 * suggest they go and do something else. Said in the rail, it is the thing
 * they come looking for after they have finished here.
 *
 * A list and not a run of links in a line: each one gets a sentence saying
 * what is over there, because "Appearance" on its own is not a reason to
 * leave the page you are on.
 *
 * One of them is not like the others: the link to a provider's own console or
 * documentation leaves the site altogether, and somebody halfway through a
 * list of steps does not want the steps replaced by somebody else's website.
 * `external` is what a screen says instead of writing the attributes itself —
 * it opens elsewhere, says so in the link, and carries the `rel` that makes
 * that safe.
 *
 * @param string                           $title What they have in common.
 * @param array<int, array<string, mixed>> $links Each: label, url and optionally
 *                                                help and external.
 */
function diluxone_users_ui_links( string $title, array $links ): void {
	if ( array() === $links ) {
		return;
	}

	echo '<div class="du-links">';

	if ( '' !== $title ) {
		printf( '<span class="du-links__title">%s</span>', esc_html( $title ) );
	}

	echo '<ul class="du-links__list">';

	foreach ( $links as $link ) {
		echo '<li>';

		$away = ! empty( $link['external'] );

		printf(
			'<a href="%1$s"%2$s>%3$s%4$s</a>',
			esc_url( (string) $link['url'] ),
			$away ? ' target="_blank" rel="noopener noreferrer"' : '',
			esc_html( (string) $link['label'] ),
			$away
				// The arrow is the sign; the sentence beside it is what a
				// screen reader gets, since an arrow read out is nothing.
				? '<span class="du-links__away" aria-hidden="true">&#8599;</span>'
					. '<span class="screen-reader-text">'
					. esc_html__( '(opens in a new tab)', 'diluxone-users' )
					. '</span>'
				: ''
		);

		if ( ! empty( $link['help'] ) ) {
			printf( '<span class="du-links__help">%s</span>', esc_html( (string) $link['help'] ) );
		}

		echo '</li>';
	}

	echo '</ul></div>';
}

/**
 * What a piece was handed, as the paragraphs it is going to print.
 *
 * One sentence stays one sentence. A list becomes one paragraph each, with the
 * empties dropped so a caller can build the list with a conditional in it and
 * not have to strip the gap afterwards.
 *
 * @param string|array<int|string, string> $body
 * @return array<int, string>
 */
function diluxone_users_ui_paragraphs( $body ): array {
	$lines = array_map( 'strval', is_array( $body ) ? array_values( $body ) : array( $body ) );

	return array_values(
		array_filter(
			array_map( 'trim', $lines ),
			static function ( string $line ): bool {
				return '' !== $line;
			}
		)
	);
}

/**
 * A warning or an aside, inside the screen, about the screen.
 *
 * Not `diluxone_users_notice()`, which is WordPress's strip at the top of the
 * page and belongs to what just happened — saved, failed. This one belongs to
 * a place: the registration tab saying this site has WordPress's own sign-ups
 * closed sits with the tick boxes it is about, three inches from them, not
 * above the title where it reads as news.
 *
 * Two tones and no more. A warning is something that will bite: a setting
 * below that cannot take effect the way the site is set up. Information is
 * the rest. Both take their colour the way everything here does — the
 * warning from WordPress's own amber, the aside from the colour scheme the
 * person picked.
 *
 * @param string|array<int|string, string> $text What it says, as one
 *                                                paragraph or as several.
 *                                                Markup allowed: it often
 *                                                points somewhere.
 * @param string                           $tone 'warning' or 'info'.
 */
function diluxone_users_ui_notice( $text, string $tone = 'info' ): void {
	printf(
		'<div class="du-notice du-notice--%s">',
		'warning' === $tone ? 'warning' : 'info'
	);

	foreach ( diluxone_users_ui_paragraphs( $text ) as $paragraph ) {
		printf( '<p>%s</p>', wp_kses_post( $paragraph ) );
	}

	echo '</div>';
}

/**
 * Whether a group where none ticked is not an answer got at least one.
 *
 * The browser is asked the same question by `diluxone_users_ui_choices()`, and
 * the browser is not the one that decides. `novalidate` is one attribute away
 * in the inspector and a form posted by a script never had a browser at all —
 * the plugin has already been bitten by exactly this on the registration form,
 * where every field was required of the browser only and of nobody else.
 *
 * So a screen asks this before it writes anything, and returns if the answer
 * is no. Before, and not after: a save that half went through leaves a site
 * with no way into an account and no way to tell from looking at the screen,
 * because the screen would be showing what was rejected as though it had been
 * kept.
 *
 * What counts as ticked is what `$_POST` carries: an unticked box sends
 * nothing, and the screens that keep a box's off-state as a literal `0` get
 * that read as nothing too.
 *
 * @param array<int|string, mixed> $chosen    The submitted values of the group.
 * @param string                   $complaint What the administrator is told. The same sentence the group carries.
 * @return bool True when at least one arrived, and the caller may save.
 */
function diluxone_users_ui_needs_one( array $chosen, string $complaint ): bool {
	foreach ( $chosen as $one ) {
		if ( is_array( $one ) ) {
			if ( array() !== $one ) {
				return true;
			}

			continue;
		}

		if ( is_scalar( $one ) && '' !== (string) $one && '0' !== (string) $one ) {
			return true;
		}
	}

	diluxone_users_notice( $complaint, 'error' );

	return false;
}
