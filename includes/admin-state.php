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
 * Every summary tab of every screen is drawn with the table below, so the
 * first thing a screen shows is the same shape on all of them.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * The four states and their words.
 *
 * @return array<string, string>
 */
function diluxone_users_states(): array {
	return array(
		'active'  => __( 'Active', 'diluxone-users' ),
		'pending' => __( 'Pending', 'diluxone-users' ),
		'off'     => __( 'Off', 'diluxone-users' ),
		'unknown' => __( 'Cannot tell', 'diluxone-users' ),
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
 * @param string $state One of active|pending|off|unknown.
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
 * @param array<int, array<string, string>> $rows Each: label, state, and optionally why, word, detail, url, change.
 */
function diluxone_users_summary_table( array $rows ): void {
	?>
	<table class="widefat striped diluxone-users-summary">
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<th scope="row" class="diluxone-users-summary__what"><?php echo esc_html( (string) $row['label'] ); ?></th>
					<td class="diluxone-users-summary__state"><?php echo diluxone_users_state_pill( (string) $row['state'], (string) ( $row['why'] ?? '' ), (string) ( $row['word'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></td>
					<td class="diluxone-users-summary__detail"><?php echo wp_kses_post( (string) ( $row['detail'] ?? '' ) ); ?></td>
					<td class="diluxone-users-summary__change">
						<?php if ( ! empty( $row['url'] ) ) : ?>
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
	echo diluxone_users_state_pill( 'pending' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	echo ' ' . esc_html( $why );

	if ( '' !== $url ) {
		echo ' <a href="' . esc_url( $url ) . '">' . esc_html( '' !== $go ? $go : __( 'Set it up →', 'diluxone-users' ) ) . '</a>';
	}

	echo '</p>';
}

