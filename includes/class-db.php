<?php
defined( 'ABSPATH' ) || exit;

class FormTheory_DB {

	public static function forms_table() {
		global $wpdb;
		return $wpdb->prefix . 'formtheory_forms';
	}

	public static function entries_table() {
		global $wpdb;
		return $wpdb->prefix . 'formtheory_entries';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$forms   = self::forms_table();
		$entries = self::entries_table();

		dbDelta( "CREATE TABLE $forms (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(255) NOT NULL DEFAULT '',
			config longtext NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id)
		) $charset;" );

		dbDelta( "CREATE TABLE $entries (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			data longtext NOT NULL,
			ip varchar(45) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id)
		) $charset;" );

		self::protect_upload_dir();
		update_option( 'formtheory_db_version', FORMTHEORY_VERSION );
	}

	/** Private upload dir; files served only through an authenticated admin handler. */
	public static function upload_dir() {
		$up = wp_upload_dir();
		return trailingslashit( $up['basedir'] ) . 'formtheory-private';
	}

	public static function protect_upload_dir() {
		$dir = self::upload_dir();
		wp_mkdir_p( $dir );
		if ( ! file_exists( "$dir/.htaccess" ) ) {
			file_put_contents( "$dir/.htaccess", "Require all denied\nDeny from all\n" );
		}
		if ( ! file_exists( "$dir/index.php" ) ) {
			file_put_contents( "$dir/index.php", "<?php // Silence.\n" );
		}
	}

	public static function default_config() {
		return array(
			'fields'        => array(),
			'submit_label'  => 'Submit',
			'confirmation'  => 'Thanks! Your submission was received.',
			'notify_to'     => get_option( 'admin_email' ),
			'notify_subject'=> 'New form submission',
		);
	}

	public static function get_form( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::forms_table() . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$cfg           = json_decode( $row['config'], true );
		$row['config'] = FormTheory_Fields::migrate_config( wp_parse_args( is_array( $cfg ) ? $cfg : array(), self::default_config() ) );
		return $row;
	}

	public static function get_forms() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT id, title, updated_at FROM ' . self::forms_table() . ' ORDER BY id DESC', ARRAY_A );
	}

	public static function save_form( $id, $title, array $config ) {
		global $wpdb;
		$now  = current_time( 'mysql' );
		$data = array(
			'title'      => $title,
			'config'     => wp_json_encode( $config ),
			'updated_at' => $now,
		);
		if ( $id ) {
			$wpdb->update( self::forms_table(), $data, array( 'id' => $id ), array( '%s', '%s', '%s' ), array( '%d' ) );
			return $id;
		}
		$data['created_at'] = $now;
		$wpdb->insert( self::forms_table(), $data, array( '%s', '%s', '%s', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	public static function delete_form( $id ) {
		global $wpdb;
		foreach ( self::get_entries( $id, 0, 0 ) as $e ) {
			self::delete_entry( $e['id'] );
		}
		$wpdb->delete( self::forms_table(), array( 'id' => $id ), array( '%d' ) );
	}

	public static function insert_entry( $form_id, array $data ) {
		global $wpdb;
		$wpdb->insert(
			self::entries_table(),
			array(
				'form_id'    => $form_id,
				'data'       => wp_json_encode( $data ),
				'ip'         => self::client_ip(),
				'user_id'    => get_current_user_id(),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/** $limit 0 = all. */
	public static function get_entries( $form_id, $limit = 50, $offset = 0 ) {
		global $wpdb;
		$sql = 'SELECT * FROM ' . self::entries_table() . ' WHERE form_id = %d ORDER BY id DESC';
		if ( $limit > 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' LIMIT %d OFFSET %d', $form_id, $limit, $offset ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $form_id ), ARRAY_A );
		}
		foreach ( $rows as &$r ) {
			$d         = json_decode( $r['data'], true );
			$r['data'] = is_array( $d ) ? $d : array();
		}
		return $rows;
	}

	public static function count_entries( $form_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::entries_table() . ' WHERE form_id = %d', $form_id ) );
	}

	public static function get_entry( $id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::entries_table() . ' WHERE id = %d', $id ), ARRAY_A );
		if ( $r ) {
			$d         = json_decode( $r['data'], true );
			$r['data'] = is_array( $d ) ? $d : array();
		}
		return $r;
	}

	public static function delete_entry( $id ) {
		global $wpdb;
		$entry = self::get_entry( $id );
		if ( $entry ) {
			foreach ( $entry['data'] as $val ) {
				if ( is_array( $val ) && isset( $val['file'] ) ) {
					$path = self::upload_dir() . '/' . basename( $val['file'] );
					if ( is_file( $path ) ) {
						wp_delete_file( $path );
					}
				}
			}
		}
		$wpdb->delete( self::entries_table(), array( 'id' => $id ), array( '%d' ) );
	}

	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
