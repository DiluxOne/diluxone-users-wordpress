<?php
/**
 * Datos personales.
 *
 * What it is given, in `$args`: `user`, the account (unused here: each piece draws itself).
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;
?>
<?php diluxone_users_avatar_form(); ?>
<?php diluxone_users_handle_form(); ?>
<?php
diluxone_users_fields_form();
