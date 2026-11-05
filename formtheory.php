<?php
/**
 * Plugin Name: FormTheory
 * Description: Lightweight form builder: conditional logic, file uploads, multi-page forms, entries, CSV export, email notifications.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: formtheory
 */

defined( 'ABSPATH' ) || exit;

define( 'FORMTHEORY_VERSION', '1.0.0' );
define( 'FORMTHEORY_FILE', __FILE__ );
define( 'FORMTHEORY_DIR', plugin_dir_path( __FILE__ ) );
define( 'FORMTHEORY_URL', plugin_dir_url( __FILE__ ) );

require_once FORMTHEORY_DIR . 'includes/class-db.php';
require_once FORMTHEORY_DIR . 'includes/class-fields.php';
require_once FORMTHEORY_DIR . 'includes/class-admin.php';
require_once FORMTHEORY_DIR . 'includes/class-frontend.php';

register_activation_hook( __FILE__, array( 'FormTheory_DB', 'install' ) );

add_action( 'plugins_loaded', function () {
	if ( get_option( 'formtheory_db_version' ) !== FORMTHEORY_VERSION ) {
		FormTheory_DB::install();
	}
	if ( is_admin() ) {
		new FormTheory_Admin();
	}
	new FormTheory_Frontend();
} );
