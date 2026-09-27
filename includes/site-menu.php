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
 * @param string $extra      Extra class.
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
			'classes'          => array_filter( array( 'menu-item', 'diluxone-users-menu', $extra ) ),
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
		$add( diluxone_users_menu_item( 990001, esc_html__( 'Sign in', 'diluxone-users' ), diluxone_users_login_url(), 0, 'diluxone-users-menu--sign-in' ) );
		return $items;
	}

	$user  = wp_get_current_user();
	$name  = '' !== $user->first_name ? $user->first_name : $user->display_name;
	$style = (string) diluxone_users_option( 'diluxone_users_menu_style' );
	// get_avatar_url() and not the uploaded photo alone: it is the same
	// picture the rest of the site shows, and it always has one to give.
	$photo = sprintf(
		'<img class="diluxone-users-menu__avatar" src="%s" alt="" width="32" height="32" loading="lazy" decoding="async">',
		esc_url( (string) get_avatar_url( (int) $user->ID, array( 'size' => 64 ) ) )
	);
	$title = match ( $style ) {
		'avatar' => $photo . '<span class="screen-reader-text">' . esc_html( $name ) . '</span>',
		'name'   => esc_html( $name ),
		default  => $photo . '<span class="diluxone-users-menu__name">' . esc_html( $name ) . '</span>',
	};

	$account = diluxone_users_account_url();
	$add( diluxone_users_menu_item( 990001, $title, '' !== $account ? $account : admin_url( 'profile.php' ), 0, 'diluxone-users-menu--person' ) );

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
 * The photo as a circle beside the name. The ring takes the colour of the
 * menu link itself, so it matches whatever the theme paints its menu with.
 */
function diluxone_users_menu_style(): void {
	if ( diluxone_users_menu_id() <= 0 || ! is_user_logged_in() ) {
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
