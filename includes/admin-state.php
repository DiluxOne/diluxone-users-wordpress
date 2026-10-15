<?php
/**
 * One vocabulary for "how is this doing", and one way of drawing it.
 *
 * Every screen used to say it differently: Open/Closed/Not yet on one, Ready/
 * Pending/Cannot tell on another, Not set up/Needs testing/Active on a third,
 * ok/warn/fail on a fourth. Four words for the same three facts, and a person
 * reading two screens in a row had to translate between them.
 *
 * Three states, always the same three: it works (active), it is half set up
 * or waits on something that is missing (pending, and it always says what),
 * or it is off. A fourth — unknown — exists only for the things nobody can
 * tell from inside the site, like whether e-mail is being delivered.
 *
 * A fifth — unset, "Not configured" — is for a setting nobody has decided.
 * Activating the plugin changes nothing, and a screen that drew the default
 * as Active or Off was saying somebody had chosen it: on a fresh install
 * every pill said so, and a person could not tell what the plugin was doing
 * (nothing) from what somebody had set up. A row in that state says what
 * WordPress does on its own meanwhile; a value saved equal to the default is
 * a decision and reads as one (`diluxone_users_option_is_set()`).
 *
 * Every summary tab of every screen is drawn with the table below, so the
 * first thing a screen shows is the same shape on all of them.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * The five states and their words.
 *
 * @return array<string, string>
 */
function diluxone_users_states(): array {
	return array(
		'active'  => __( 'Active', 'diluxone-users' ),
		'pending' => __( 'Pending', 'diluxone-users' ),
		'off'     => __( 'Off', 'diluxone-users' ),
		'unknown' => __( 'Cannot tell', 'diluxone-users' ),
		'unset'   => __( 'Not configured', 'diluxone-users' ),
		// Not how something here is doing: what it would take. A feature of
		// Users+ Pro, drawn where it belongs (diluxone_users_ui_pro()).
		'pro'     => __( 'Pro', 'diluxone-users' ),
	);
}

/**
 * A state, drawn as a pill.
 *
 * The word can be replaced and the state cannot, and that is the whole of what
 * $word is for. Most things on these screens are switches, and a switch is
 * Active or Off in any language. A few are not: the second step asked of
 * somebody arriving by link is a question, and a question is asked or it is
 * not — "Off" beside it reads as though the link itself had been turned off,
 * which is exactly how somebody read it. So the colour, the shape and the
 * three states stay the one vocabulary every screen speaks, and the word says
 * what the thing beside it actually does.
 *
 * @param string $state One of active|pending|off|unknown|unset|pro.
 * @param string $why   The reason, for a pending one: it is never shown bare.
 * @param string $word  The verb of the thing, when the state's own word would
 *                      name a switch that is not there.
 */
function diluxone_users_state_pill( string $state, string $why = '', string $word = '' ): string {
	$states = diluxone_users_states();
	$state  = isset( $states[ $state ] ) ? $state : 'unknown';

	$html = sprintf(
		'<span class="diluxone-users-state diluxone-users-state--%1$s">%2$s</span>',
		esc_attr( $state ),
		esc_html( '' !== $word ? $word : $states[ $state ] )
	);

	if ( '' !== $why ) {
		$html .= ' <span class="diluxone-users-state__why">' . esc_html( $why ) . '</span>';
	}

	return $html;
}

/**
 * The summary table every screen opens with.
 *
 * A row is what it is, how it is doing, a detail in one line, and where to
 * change it. The link is optional and always says the same thing, so it is
 * found in the same place on every row.
 *
 * A row can replace the pill's word the same way a note can, and for the same
 * reason: most rows are switches and read as Active or Off, but some name a
 * thing that has no switch on it — a toolbar, a page that was never chosen,
 * the mail going out. "Off" beside one of those says somebody turned it off,
 * which is how the second step on the 2FA rail was read before it was given a
 * word of its own. The state, the colour and the shape stay the one
 * vocabulary; `word` only says what that state is called here.
 *
 * A row Users+ Pro would add (diluxone_users_ui_pro_row()) carries `pro`, the
 * placeholder's id, and its link leaves the dashboard, which `external` says.
 *
 * @param array<int, array<string, string>> $rows Each: label, state, and optionally why, word, detail, url, change, external and pro.
 */
function diluxone_users_summary_table( array $rows ): void {
	?>
	<table class="widefat striped diluxone-users-summary">
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr<?php echo ! empty( $row['pro'] ) ? ' class="du-pro du-pro--row" data-diluxone-users-pro="' . esc_attr( (string) $row['pro'] ) . '"' : ''; ?>>
					<th scope="row" class="diluxone-users-summary__what"><?php echo esc_html( (string) $row['label'] ); ?></th>
					<td class="diluxone-users-summary__state"><?php echo wp_kses( diluxone_users_state_pill( (string) $row['state'], (string) ( $row['why'] ?? '' ), (string) ( $row['word'] ?? '' ) ), diluxone_users_allowed_html() ); ?></td>
					<td class="diluxone-users-summary__detail"><?php echo wp_kses_post( (string) ( $row['detail'] ?? '' ) ); ?></td>
					<td class="diluxone-users-summary__change">
						<?php if ( ! empty( $row['url'] ) && ! empty( $row['external'] ) ) : ?>
							<a href="<?php echo esc_url( (string) $row['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) ( $row['change'] ?? '' ) ); ?><span class="screen-reader-text"> <?php /* translators: Accessibility text. */ esc_html_e( '(opens in a new tab)', 'diluxone-users' ); ?></span></a>
						<?php elseif ( ! empty( $row['url'] ) ) : ?>
							<a href="<?php echo esc_url( (string) $row['url'] ); ?>"><?php echo esc_html( (string) ( $row['change'] ?? __( 'Change it →', 'diluxone-users' ) ) ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * A row of the summary, read from a setting somebody may not have decided.
 *
 * Every row that reads a setting goes through here: the row as the caller
 * built it when the setting is decided — stored, or pinned from code — and,
 * when nobody has decided it, the same row saying so: Not configured, with
 * what WordPress does on its own in the detail column and no reason beside
 * the pill. A row that reads several settings names them all, and is decided
 * once any of them is.
 *
 * It is a function over the row and not a flag on it so the caller still
 * writes the Active/Off/Pending logic it would write anyway, and the one
 * sentence about the default on top of it.
 *
 * @param string|array<int, string> $keys      The setting, or the settings, the row reads.
 * @param array<string, string>     $row       The row, as diluxone_users_summary_table() takes it.
 * @param string                    $meanwhile What WordPress does on its own while nobody has decided.
 * @return array<string, string>
 */
function diluxone_users_summary_row( $keys, array $row, string $meanwhile ): array {
	if ( diluxone_users_options_any_set( (array) $keys ) ) {
		return $row;
	}

	unset( $row['word'] );

	$row['state']  = 'unset';
	$row['why']    = '';
	$row['detail'] = $meanwhile;

	return $row;
}

/**
 * A block of controls that waits for another answer, and what it waits for.
 *
 * The sentence above a group that is drawn disabled with nothing chosen:
 * what becomes of wp-login.php while no sign-in page is chosen, who the
 * second step is asked of while it is off. No pill — a pill says how the
 * thing is doing, and a block nobody can touch yet is not doing anything —
 * and, like `diluxone_users_not_now()`, in the main column above what it is
 * about. `diluxone_users_ui_choices()` prints it for a group handed `$waits`;
 * a screen prints it by hand above a block that is not a group.
 *
 * @param string $why What it waits for, in the present tense.
 * @param string $url Where to give it, if anywhere.
 * @param string $go  What the link says.
 */
function diluxone_users_ui_waits( string $why, string $url = '', string $go = '' ): void {
	echo '<p class="du-waits">' . esc_html( $why );

	if ( '' !== $url ) {
		echo ' <a href="' . esc_url( $url ) . '">' . esc_html( '' !== $go ? $go : __( 'Set it up →', 'diluxone-users' ) ) . '</a>';
	}

	echo '</p>';
}

/**
 * A control that does not apply right now, and why.
 *
 * A piece of the design system, and the one that does not go in the rail. The
 * rail carries what a screen has to say about itself; this says something
 * about the three controls immediately under it, and in the other column it
 * would be a sentence with nothing to point at. So it stays in the main
 * column, above what it is about.
 *
 * The control itself stays enabled and saves as always — what is chosen today
 * applies the day the reason goes away — and the reason and the way out are
 * the first thing read, not a line at the foot.
 *
 * @param string $why The reason, in the present tense.
 * @param string $url Where to fix it, if anywhere.
 * @param string $go  What the link says.
 */
function diluxone_users_not_now( string $why, string $url = '', string $go = '' ): void {
	echo '<p class="diluxone-users-not-now">';
	echo wp_kses( diluxone_users_state_pill( 'pending' ), diluxone_users_allowed_html() );
	echo ' ' . esc_html( $why );

	if ( '' !== $url ) {
		echo ' <a href="' . esc_url( $url ) . '">' . esc_html( '' !== $go ? $go : __( 'Set it up →', 'diluxone-users' ) ) . '</a>';
	}

	echo '</p>';
}

