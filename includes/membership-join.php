<?php
/**
 * "This site is by invitation", and what an add-on offers instead.
 *
 * A signed-in person who is not a member of a site of the network, where the
 * policy does not add them, is told that only its administrators add people.
 * It is said in three places: the site's menu (site-menu.php), the
 * `[diluxone_users_join]` shortcode, and a notice at the top of the page the
 * hub sends them back to after signing in. Somebody an administrator took off
 * a site hears it whatever the policy: nothing but an administrator undoes
 * that decision.
 *
 * An add-on whose policy lets people join on their own (Users+ Pro's "whoever
 * asks") answers a state of its own for them through
 * `diluxone_users_join_state`, and draws it: the box through
 * `diluxone_users_join_box`, the menu's link through `diluxone_users_join_link`.
 * It adds the person through diluxone_users_membership_add(), with 'click'.
 *
 * On a single site everybody with an account is a member already: the
 * shortcode draws nothing, and there is no handler.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * What a person can do about the current site: 'invite', '' or an add-on's.
 *
 * '' for nobody signed in, a member, a super admin, a site that takes nobody,
 * and under "every site" for anybody who has not been taken off it: they are
 * added when they sign in, and there is nothing to press.
 *
 * @param int $user_id Who; the person signed in when left out.
 */
function diluxone_users_join_state( int $user_id = 0 ): string {
	// On a single site WordPress counts every account a member: '' below.
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();
	$site_id = get_current_blog_id();

	if ( $user_id <= 0 || is_super_admin( $user_id ) || is_user_member_of_blog( $user_id, $site_id ) || ! diluxone_users_membership_site_takes( $site_id ) ) {
		return '';
	}

	$policy = diluxone_users_membership();

	if ( in_array( $site_id, diluxone_users_membership_removed( $user_id ), true ) ) {
		return 'invite';
	}

	if ( 'all' === $policy ) {
		return '';
	}

	/**
	 * Filters what somebody signed in, not a member of this site and not
	 * taken off it, can do about it under a policy that is not "every site".
	 *
	 * The plugin answers 'invite': an administrator adds people. An add-on
	 * whose policy lets them join on their own answers a state of its own
	 * (Users+ Pro's 'click'), and draws it through `diluxone_users_join_box`
	 * and `diluxone_users_join_link`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $state   'invite'.
	 * @param string $policy  The network's policy, as read ('hub' for one nothing offers).
	 * @param int    $user_id Who.
	 * @param int    $site_id Which site.
	 */
	$state = apply_filters( 'diluxone_users_join_state', 'invite', $policy, $user_id, $site_id );

	return is_string( $state ) && '' !== $state ? sanitize_key( $state ) : 'invite';
}

/**
 * The address the hub sends somebody back to, saying there is a site to join.
 *
 * When the site they are going back to is not one they are a member of — under
 * "click" or "invite", or because they were taken off it — the page they land
 * on says so at the top (diluxone_users_join_notice()).
 *
 * @param string $url     Where they are going back to.
 * @param int    $user_id Who.
 */
function diluxone_users_join_mark( string $url, int $user_id ): string {
	// No site of a network on a single site, and a super admin reaches every
	// site already.
	$site = diluxone_users_site_for_url( diluxone_users_safe_return( $url ) );

	if ( $site <= 0 || diluxone_users_hub_site_id() === $site || is_super_admin( $user_id ) || is_user_member_of_blog( $user_id, $site ) ) {
		return $url;
	}

	return add_query_arg( 'diluxone-users', 'join', $url );
}

/**
 * A password typed on the hub's wp-login.php, going back to a site to join.
 *
 * @param string           $redirect  Where WordPress will send them.
 * @param string           $requested Where they asked to go.
 * @param WP_User|WP_Error $user      Who signed in, or why not.
 */
function diluxone_users_join_mark_password( $redirect, $requested = '', $user = null ): string {
	return $user instanceof WP_User ? diluxone_users_join_mark( (string) $redirect, (int) $user->ID ) : (string) $redirect;
}

if ( is_multisite() ) {
	add_filter( 'login_redirect', 'diluxone_users_join_mark_password', 20, 3 );
}

/**
 * The box that says it: by invitation, joined, or an add-on's.
 *
 * @param bool $notice Drawn at the top of the page rather than where a shortcode is.
 */
function diluxone_users_join_box( bool $notice ): string {
	$state = diluxone_users_join_state();
	$said  = diluxone_users_state();

	if ( '' === $state ) {
		if ( 'joined' !== $said || ! is_user_member_of_blog( get_current_user_id(), get_current_blog_id() ) ) {
			return '';
		}

		$state = 'joined';
	}

	diluxone_users_enqueue_styles();

	if ( ! in_array( $state, array( 'invite', 'joined' ), true ) ) {
		/**
		 * Filters the box for a state an add-on answered through
		 * `diluxone_users_join_state`: Users+ Pro's "Join this site".
		 *
		 * Left empty, the person is told the site is by invitation.
		 *
		 * @since 1.0.0
		 *
		 * @param string $box    The box's markup.
		 * @param string $state  The add-on's state.
		 * @param bool   $notice Drawn at the top of the page rather than where a shortcode is.
		 */
		$box = (string) apply_filters( 'diluxone_users_join_box', '', $state, $notice );

		if ( '' !== $box ) {
			return $box;
		}

		$state = 'invite';
	}

	return diluxone_users_render(
		'join',
		array(
			'state'  => $state,
			'site'   => (string) get_bloginfo( 'name' ),
			'hub'    => diluxone_users_hub_name(),
			'notice' => $notice,
		)
	);
}

/**
 * Whether the box was drawn at the top of this page already.
 *
 * @param bool|null $drawn True once it is, false to forget it, null to ask.
 */
function diluxone_users_join_drawn( ?bool $drawn = null ): bool {
	static $done = false;

	if ( null !== $drawn ) {
		$done = $drawn;
	}

	return $done;
}

/**
 * [diluxone_users_join]: the box, where a site puts it.
 *
 * Nothing on a single site, for nobody signed in, or for a member.
 */
function diluxone_users_shortcode_join(): string {
	if ( ! is_multisite() || ! is_user_logged_in() || diluxone_users_join_drawn() ) {
		return '';
	}

	$box = diluxone_users_join_box( false );

	// Said once a page, whichever is drawn first: a block theme renders the
	// content, shortcode included, before the top of the page opens.
	if ( '' !== $box ) {
		diluxone_users_join_drawn( true );
	}

	return $box;
}
add_shortcode( 'diluxone_users_join', 'diluxone_users_shortcode_join' );

/** Is this page one the hub or "Join this site" just sent somebody to? */
function diluxone_users_join_arriving(): bool {
	return is_multisite() && is_user_logged_in() && in_array( diluxone_users_state(), array( 'join', 'joined', 'join-refused' ), true );
}

/** The stylesheet, in the head, when the box is going to be drawn at the top. */
function diluxone_users_join_styles(): void {
	if ( diluxone_users_join_arriving() ) {
		diluxone_users_enqueue_styles();
	}
}
add_action( 'wp_enqueue_scripts', 'diluxone_users_join_styles', 20 );

/** The box at the top of the page somebody was sent back to. */
function diluxone_users_join_notice(): void {
	if ( ! diluxone_users_join_arriving() || diluxone_users_join_drawn() ) {
		return;
	}

	$box = diluxone_users_join_box( true );

	if ( '' === $box ) {
		return;
	}

	diluxone_users_join_drawn( true );

	echo wp_kses( $box, diluxone_users_allowed_html() );
}
add_action( 'wp_body_open', 'diluxone_users_join_notice' );
