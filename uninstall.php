<?php
/**
 * Runs when the plugin is deleted. Removes data only if the admin opted in.
 * Single-site scope; multisite per-blog cleanup is not handled.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$formtheory_settings = get_option( 'formtheory_settings' );
if ( ! is_array( $formtheory_settings ) || empty( $formtheory_settings['delete_on_uninstall'] ) ) {
	return;
}

require_once __DIR__ . '/includes/class-db.php';
FormTheory_DB::uninstall();
