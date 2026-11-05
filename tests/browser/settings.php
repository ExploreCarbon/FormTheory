<?php
define( 'ABSPATH', __DIR__ );
function __( $s, $domain = '' ) { return $s; }
function esc_html__( $s, $domain = '' ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_attr__( $s, $domain = '' ) { return esc_html__( $s ); }
function esc_html_e( $s, $domain = '' ) { echo esc_html__( $s ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function current_user_can( $s ) { return true; }
function settings_errors() {}
function settings_fields( $s ) {}
function get_option( $s, $default = false ) { return $default; }
function wp_parse_args( $a, $b ) { return array_merge( $b, $a ); }
function checked( $a, $b = true, $echo = true ) { $s = $a == $b ? ' checked' : ''; if ( $echo ) echo $s; return $s; }
function selected( $a, $b = true, $echo = true ) { $s = $a == $b ? ' selected' : ''; if ( $echo ) echo $s; return $s; }
function disabled( $a, $b = true, $echo = true ) { $s = $a == $b ? ' disabled' : ''; if ( $echo ) echo $s; return $s; }
function submit_button() { echo '<p class="submit"><button type="submit" class="button button-primary">Save Changes</button></p>'; }
class FormTheory_Spam { public static function settings() { return array( 'turnstile_site'=>'', 'turnstile_secret'=>'', 'delete_on_uninstall'=>false ); } }
class FormTheory_Privacy { public static function retention_days() { return 0; } }
require dirname( __DIR__, 2 ) . '/includes/class-admin.php';
require dirname( __DIR__, 2 ) . '/includes/class-recaptcha.php';
$admin = ( new ReflectionClass( 'FormTheory_Admin' ) )->newInstanceWithoutConstructor();
$admin->page_settings();

