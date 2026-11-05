<?php
/**
 * Plugin Name: FormTheory
 * Description: Lightweight form builder: conditional logic, file uploads, multi-page forms, entries, CSV export, email notifications.
 * Version: 1.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: formtheory
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'FORMTHEORY_VERSION', '1.2.0' );
define( 'FORMTHEORY_FILE', __FILE__ );
define( 'FORMTHEORY_DIR', plugin_dir_path( __FILE__ ) );
define( 'FORMTHEORY_URL', plugin_dir_url( __FILE__ ) );

require_once FORMTHEORY_DIR . 'includes/class-db.php';
require_once FORMTHEORY_DIR . 'includes/class-fields.php';
require_once FORMTHEORY_DIR . 'includes/class-calc.php';
require_once FORMTHEORY_DIR . 'includes/class-merge.php';
require_once FORMTHEORY_DIR . 'includes/class-spam.php';
require_once FORMTHEORY_DIR . 'includes/class-notify.php';
require_once FORMTHEORY_DIR . 'includes/class-webhooks.php';
require_once FORMTHEORY_DIR . 'includes/class-recaptcha.php';
require_once FORMTHEORY_DIR . 'includes/class-portable.php';
require_once FORMTHEORY_DIR . 'includes/class-privacy.php';
require_once FORMTHEORY_DIR . 'includes/class-admin.php';
require_once FORMTHEORY_DIR . 'includes/class-frontend.php';

add_action( 'init', function () {
	load_plugin_textdomain( 'formtheory', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

register_activation_hook( __FILE__, array( 'FormTheory_DB', 'install' ) );
register_deactivation_hook( __FILE__, array( 'FormTheory_Privacy', 'unschedule' ) );

add_action( 'plugins_loaded', function () {
	if ( get_option( 'formtheory_db_version' ) !== FORMTHEORY_VERSION ) {
		FormTheory_DB::install();
	}
	if ( is_admin() ) {
		new FormTheory_Admin();
	}
	new FormTheory_Frontend();
	FormTheory_Webhooks::init();
	FormTheory_Privacy::init();
} );
