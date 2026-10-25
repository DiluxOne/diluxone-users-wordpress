<?php
/**
 * The person in the site's own menu.
 *
 * A site with accounts needs a door in its header: "Sign in" for whoever is a
 * visitor, and for whoever is signed in their face or their name, with their
 * account one click away. Every theme already draws a menu and its dropdowns
 * its own way, so the plugin does not draw one: it adds real items to the
 * menu the site chose, and the theme renders them exactly like the rest —
 * same classes, same dropdown, same mobile menu.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * The menu locations of the active theme, for the setting.
 *
 * @return array<string, string> Location => what the theme calls it.
 */
function diluxone_users_menu_locations(): array {
	return array( '' => __( '— Nowhere —', 'diluxone-users' ) ) + get_registered_nav_menus();
}

/**
 * How the signed-in person is shown in the menu.
 *
 * @return array<string, string>
 */
function diluxone_users_menu_styles(): array {
	return array(
		'avatar-name' => __( 'Photo and name', 'diluxone-users' ),
		'avatar'      => __( 'Photo only', 'diluxone-users' ),
		'name'        => __( 'Name only', 'diluxone-users' ),
	);
}

/**
 * How "Sign in" is drawn for a visitor.
 *
 * @return array<string, string>
 */
function diluxone_users_menu_signin_styles(): array {
	return array(
		'link'     => __( 'A link, like the rest of the menu', 'diluxone-users' ),
		'filled'   => __( 'A filled button', 'diluxone-users' ),
		'outlined' => __( 'An outlined button', 'diluxone-users' ),
	);
}

/** The stored "Sign in" style, or the link when it is not one of them. */
function diluxone_users_menu_signin_style(): string {
	$style = (string) diluxone_users_option( 'diluxone_users_menu_signin_style' );
	return isset( diluxone_users_menu_signin_styles()[ $style ] ) ? $style : 'link';
}

/**
 * Whose colours a "Sign in" button takes.
 *
 * @return array<string, string>
 */
function diluxone_users_menu_signin_color_modes(): array {
	return array(
		'theme' => __( 'The theme’s', 'diluxone-users' ),
		'own'   => __( 'Your own', 'diluxone-users' ),
	);
}

/** The stored colour mode, or the theme's when it is not one of them. */
function diluxone_users_menu_signin_color_mode(): string {
	$mode = (string) diluxone_users_option( 'diluxone_users_menu_signin_colors' );
	return isset( diluxone_users_menu_signin_color_modes()[ $mode ] ) ? $mode : 'theme';
}

/**
 * The four colours of a "Sign in" button in the site's own colours.
 *
 * One set for both schemes: the colours belong to the button and not to the
 * page, so a contrast checked once on save holds on a light page and a dark
 * one alike.
 *
 * @return array<string, string> bg, text, border, hover => a hex colour.
 */
function diluxone_users_menu_signin_palette(): array {
	$defaults = diluxone_users_option_defaults();
	$palette  = array();
	foreach ( array( 'bg', 'text', 'border', 'hover' ) as $part ) {
		$key              = 'diluxone_users_menu_signin_' . $part;
		$hex              = sanitize_hex_color( (string) diluxone_users_option( $key ) );
		$palette[ $part ] = is_string( $hex ) && '' !== $hex ? $hex : (string) $defaults[ $key ];
	}
	return $palette;
}

/**
 * Whether the text of a "Sign in" button reads on its ground and on its
 * hover ground: 4.5:1 at least, WCAG's AA for text.
 *
 * @param array<string, string> $palette bg, text, border, hover.
 */
function diluxone_users_menu_signin_palette_reads( array $palette ): bool {
	return diluxone_users_contrast( $palette['text'], $palette['bg'] ) >= 4.5
		&& diluxone_users_contrast( $palette['text'], $palette['hover'] ) >= 4.5;
}

/** The ID of the menu assigned to the chosen location, or 0. */
function diluxone_users_menu_id(): int {
	$location = (string) diluxone_users_option( 'diluxone_users_menu_location' );
	if ( '' === $location ) {
		return 0;
	}
	$locations = get_nav_menu_locations();
	return (int) ( $locations[ $location ] ?? 0 );
}

/**
 * One menu item the walker can draw: the same fields a saved item has.
 *
 * @param int    $id     A number no saved item uses.
 * @param string $title  May carry markup (the photo); built and escaped here.
 * @param string $url    Where it goes.
 * @param int    $hangs_from The item it hangs from, or 0.
 * @param string $extra      Extra classes, separated by spaces.
 */
function diluxone_users_menu_item( int $id, string $title, string $url, int $hangs_from = 0, string $extra = '' ): WP_Post {
	return new WP_Post(
		(object) array(
			'ID'               => $id,
			'db_id'            => $id,
			'post_type'        => 'nav_menu_item',
			'post_status'      => 'publish',
			'post_title'       => wp_strip_all_tags( $title ),
			'title'            => $title,
			'url'              => $url,
			'menu_item_parent' => (string) $hangs_from,
			'object_id'        => $id,
			'object'           => 'custom',
			'type'             => 'custom',
			'type_label'       => '',
			'target'           => '',
			'attr_title'       => '',
			'description'      => '',
			'xfn'              => '',
			'classes'          => array_merge( array( 'menu-item', 'diluxone-users-menu' ), array_values( array_filter( explode( ' ', $extra ) ) ) ),
			'current'          => false,
			'menu_order'       => 0,
			'post_parent'      => 0,
		)
	);
}

/**
 * Adds the person to the chosen menu, as its last items.
 *
 * @param array<int, WP_Post> $items The menu's items, sorted.
 * @param stdClass            $args  wp_nav_menu() arguments.
 * @return array<int, WP_Post>
 */
function diluxone_users_menu_items( $items, $args ) {
	$menu = diluxone_users_menu_id();
	if ( $menu <= 0 || ! isset( $args->menu ) || ! $args->menu instanceof WP_Term || (int) $args->menu->term_id !== $menu ) {
		return $items;
	}

	$order = count( $items );
	$add   = static function ( WP_Post $item ) use ( &$items, &$order ): void {
		$item->menu_order = ++$order;
		$items[]          = $item;
	};

	if ( ! is_user_logged_in() ) {
		$button = diluxone_users_menu_signin_style();
		$look   = 'link' === $button ? '' : ' diluxone-users-menu--button diluxone-users-menu--' . $button . ( 'own' === diluxone_users_menu_signin_color_mode() ? ' diluxone-users-menu--own' : '' );
		$add( diluxone_users_menu_item( 990001, esc_html__( 'Sign in', 'diluxone-users' ), diluxone_users_login_url(), 0, 'diluxone-users-menu--sign-in' . $look ) );
		return $items;
	}

	$user  = wp_get_current_user();
	$name  = '' !== $user->first_name ? $user->first_name : $user->display_name;
	$style = (string) diluxone_users_option( 'diluxone_users_menu_style' );
	// get_avatar_url() and not the uploaded photo alone: it is the same
	// picture the rest of the site shows, and it always has one to give.
	$photo = sprintf(
		'<img class="diluxone-users-menu__avatar" src="%s" alt="" width="32" height="32" loading="lazy" decoding="async">',
		diluxone_users_avatar_src( (string) get_avatar_url( (int) $user->ID, array( 'size' => 64 ) ) )
	);
	$title = match ( $style ) {
		'avatar' => $photo . '<span class="screen-reader-text">' . esc_html( $name ) . '</span>',
		'name'   => esc_html( $name ),
		default  => $photo . '<span class="diluxone-users-menu__name">' . esc_html( $name ) . '</span>',
	};

	// On a network, somebody signed in who is not a member of this site:
	// the word that it is by invitation, or the way to join it an add-on
	// offers (see membership-join.php). Nothing on a single site.
	$join = diluxone_users_join_state();

	if ( '' !== $join && ! in_array( $join, array( 'invite', 'joined' ), true ) ) {
		/**
		 * Filters the menu's link for a state an add-on answered through
		 * `diluxone_users_join_state`: Users+ Pro's "Join this site".
		 *
		 * An array with `label` (plain text) and `url`, or empty for none —
		 * and then the menu says the site is by invitation.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $link  label and url.
		 * @param string                $state The add-on's state.
		 */
		$link = (array) apply_filters( 'diluxone_users_join_link', array(), $join );

		if ( ! empty( $link['label'] ) && ! empty( $link['url'] ) ) {
			$add( diluxone_users_menu_item( 990002, esc_html( (string) $link['label'] ), (string) $link['url'], 0, 'diluxone-users-menu--join' ) );
		} else {
			$join = 'invite';
		}
	}

	// Without an account page there is no account to go to: the address of
	// one falls back to the home page, which is no place for "my account".
	$account = '' !== diluxone_users_page_url( 'diluxone_users_account_page' ) ? diluxone_users_account_url() : '';
	// wp_nav_menu() marks the items with children before this filter runs,
	// so the person's has to say it itself: "Sign out" always hangs from it,
	// and themes draw the arrow and the drop-down from that class.
	$add( diluxone_users_menu_item( 990001, $title, '' !== $account ? $account : admin_url( 'profile.php' ), 0, 'diluxone-users-menu--person menu-item-has-children' ) );

	if ( 'invite' === $join ) {
		$add( diluxone_users_menu_item( 990003, esc_html__( 'This site is by invitation', 'diluxone-users' ), '', 990001, 'diluxone-users-menu--invite' ) );
	}

	$id = 990010;
	if ( '' !== $account ) {
		foreach ( diluxone_users_sections() as $section_id => $section ) {
			$add( diluxone_users_menu_item( ++$id, esc_html( (string) $section['label'] ), diluxone_users_account_url( (string) $section_id ), 990001 ) );
		}
	}
	if ( current_user_can( 'edit_posts' ) ) {
		$add( diluxone_users_menu_item( ++$id, esc_html__( 'Go to the WordPress dashboard', 'diluxone-users' ), admin_url(), 990001, 'diluxone-users-menu--dashboard' ) );
	}
	$add( diluxone_users_menu_item( ++$id, esc_html__( 'Sign out', 'diluxone-users' ), wp_logout_url( home_url( '/' ) ), 990001, 'diluxone-users-menu--sign-out' ) );

	return $items;
}
add_filter( 'wp_nav_menu_objects', 'diluxone_users_menu_items', 20, 2 );


/**
 * Gives "Sign in" drawn as a button the theme's own button class.
 *
 * `wp-element-button` is what theme.json and block themes paint their
 * buttons with, so the button takes the site's colours with nothing to set.
 * Only the plugin's own item is touched, and not when the site gave it
 * colours of its own: then the theme's button would paint over them.
 *
 * @param array<string, mixed> $atts The link's attributes.
 * @param WP_Post|object       $item The menu item.
 * @return array<string, mixed>
 */
function diluxone_users_menu_link_attributes( $atts, $item ) {
	$classes = isset( $item->classes ) ? (array) $item->classes : array();
	if ( ! in_array( 'diluxone-users-menu--button', $classes, true ) || in_array( 'diluxone-users-menu--own', $classes, true ) ) {
		return $atts;
	}
	$atts['class'] = trim( ( isset( $atts['class'] ) ? (string) $atts['class'] . ' ' : '' ) . 'wp-element-button' );
	return $atts;
}
add_filter( 'nav_menu_link_attributes', 'diluxone_users_menu_link_attributes', 10, 2 );

/**
 * The CSS of "Sign in" as a button, empty while it is a link.
 *
 * The theme's `.wp-element-button` paints it. The plugin's own rule is under
 * `:where()`, so it weighs nothing and only shows on a theme that does not
 * style that class. "Outlined" lays a transparent ground and a border of the
 * text's colour on top of the theme's button. With the site's own colours
 * the button is painted from the `--diluxone-users-menu-btn-*` tokens, built
 * only from colours checked on save and again here.
 */
function diluxone_users_menu_signin_css(): string {
	$style = diluxone_users_menu_signin_style();
	if ( 'link' === $style ) {
		return '';
	}

	$css = ':where(.diluxone-users-menu--button) > a{display:inline-block;padding:.5em 1.1em;border:2px solid transparent;border-radius:4px;line-height:1.2;text-decoration:none;'
		. 'background:var(--wp--preset--color--contrast,#1e1e1e);color:var(--wp--preset--color--base,#fff)}';

	if ( 'outlined' === $style ) {
		$css .= '.diluxone-users-menu--outlined > a.wp-element-button{background:transparent;color:inherit;border:2px solid currentColor}';
	}

	if ( 'own' === diluxone_users_menu_signin_color_mode() ) {
		$palette = diluxone_users_menu_signin_palette();
		$css    .= sprintf(
			'.diluxone-users-menu--own{--diluxone-users-menu-btn-bg:%1$s;--diluxone-users-menu-btn-text:%2$s;--diluxone-users-menu-btn-border:%3$s;--diluxone-users-menu-btn-hover:%4$s}',
			$palette['bg'],
			$palette['text'],
			$palette['border'],
			$palette['hover']
		)
			. '.diluxone-users-menu--sign-in.diluxone-users-menu--own > a{background:var(--diluxone-users-menu-btn-bg);color:var(--diluxone-users-menu-btn-text);border-color:var(--diluxone-users-menu-btn-border)}'
			. '.diluxone-users-menu--sign-in.diluxone-users-menu--own > a:hover,.diluxone-users-menu--sign-in.diluxone-users-menu--own > a:focus-visible{background:var(--diluxone-users-menu-btn-hover);color:var(--diluxone-users-menu-btn-text)}';
	}

	return $css;
}

/**
 * The photo as a circle beside the name. The ring takes the colour of the
 * menu link itself, so it matches whatever the theme paints its menu with.
 */
function diluxone_users_menu_style(): void {
	if ( diluxone_users_menu_id() <= 0 ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		// A visitor gets CSS only when "Sign in" is drawn other than a link.
		$css = diluxone_users_menu_signin_css();
		if ( '' !== $css ) {
			wp_register_style( 'diluxone-users-menu', false, array(), DILUXONE_USERS_VERSION );
			wp_enqueue_style( 'diluxone-users-menu' );
			wp_add_inline_style( 'diluxone-users-menu', $css );
		}
		return;
	}
	wp_register_style( 'diluxone-users-menu', false, array(), DILUXONE_USERS_VERSION );
	wp_enqueue_style( 'diluxone-users-menu' );
	wp_add_inline_style(
		'diluxone-users-menu',
		// The space beside the photo is its own margin and not a flex gap:
		// themes restyle their menu links (a mobile menu often makes them
		// blocks), and a gap on a link that stopped being flex is no space at
		// all — the name ran over the photo.
		'.diluxone-users-menu--person > a{align-items:center}'
		. '.diluxone-users-menu .diluxone-users-menu__avatar{display:inline-block;vertical-align:middle;width:32px;height:32px;margin-inline-end:.55em;border-radius:50%;object-fit:cover;box-shadow:0 0 0 2px currentColor;flex:none}'
	);
}
add_action( 'wp_enqueue_scripts', 'diluxone_users_menu_style' );
