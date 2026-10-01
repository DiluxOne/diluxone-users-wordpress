<?php
/**
 * The account-area front page: the summary.
 *
 * What it is given, in `$args`:
 *
 * - `cards`
 *
 * @var array{cards: array<int, array<string, string>>} $args
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;
?>

<?php if ( array() === $args['cards'] ) : ?>
	<p><?php esc_html_e( 'Nothing to show yet. The sections above are all yours.', 'diluxone-users' ); ?></p>
<?php else : ?>
	<div class="diluxone-users-cards">
		<?php foreach ( $args['cards'] as $diluxone_users_card ) : ?>
			<a class="diluxone-users-card-summary" href="<?php echo esc_url( $diluxone_users_card['link'] ); ?>">
				<span class="diluxone-users-card-summary__label"><?php echo esc_html( $diluxone_users_card['label'] ); ?></span>
				<?php
				// A number carries the big type; a sentence does not — at that
				// size it wraps onto three lines and drags the whole row with
				// it. Which one it is, the plugin works out.
				?>
				<span class="diluxone-users-card-summary__value <?php echo diluxone_users_card_is_figure( (string) $diluxone_users_card['value'] ) ? '' : 'is-text'; ?>"><?php echo esc_html( $diluxone_users_card['value'] ); ?></span>
				<?php if ( '' !== $diluxone_users_card['note'] ) : ?>
					<span class="diluxone-users-card-summary__note"><?php echo esc_html( $diluxone_users_card['note'] ); ?></span>
				<?php endif; ?>
				<span class="diluxone-users-card-summary__cta"><?php echo esc_html( $diluxone_users_card['cta'] ); ?></span>
			</a>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
