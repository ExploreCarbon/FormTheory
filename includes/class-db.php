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
		$prev    = get_option( 'formtheory_db_version' );

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
			status varchar(10) NOT NULL DEFAULT 'unread',
			notes text NOT NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id)
		) $charset;" );

		// Upgrading from < 1.2.0: entries that predate the status column count as read.
		if ( $prev && version_compare( (string) $prev, '1.2.0', '<' ) ) {
			$wpdb->query( "UPDATE $entries SET status = 'read'" );
		}

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
			'ajax'              => true,
			'confirmation_type' => 'message',
			'confirmation_page' => 0,
			'confirmation_url'  => '',
		);
	}

	public static function get_form( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::forms_table() . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$cfg           = json_decode( $row['config'], true );
		$cfg           = is_array( $cfg ) ? $cfg : array();
		$legacy        = ! array_key_exists( 'ajax', $cfg );
		$row['config'] = wp_parse_args( $cfg, self::default_config() );
		if ( $legacy ) {
			unset( $row['config']['ajax'] ); // Let migrate_config mark it as a 1.0 form.
		}
		$row['config'] = FormTheory_Fields::migrate_config( $row['config'] );
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

	/**
	 * Build the WHERE clause for entry listing.
	 *
	 * @param int   $form_id Form id.
	 * @param array $args    search (string), status ('' | unread | read).
	 * @return array [ string $where_sql (placeholders), array $params ]
	 */
	public static function entries_query( $form_id, array $args = array() ) {
		global $wpdb;
		$where  = 'form_id = %d';
		$params = array( (int) $form_id );

		$status = isset( $args['status'] ) ? (string) $args['status'] : '';
		if ( in_array( $status, array( 'unread', 'read' ), true ) ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}

		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		if ( '' !== $search ) {
			$where   .= ' AND data LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		return array( $where, $params );
	}

	/** $limit 0 = all. */
	public static function get_entries( $form_id, $limit = 50, $offset = 0, array $args = array() ) {
		global $wpdb;
		list( $where, $params ) = self::entries_query( $form_id, $args );
		$sql = 'SELECT * FROM ' . self::entries_table() . ' WHERE ' . $where . ' ORDER BY id DESC';
		if ( $limit > 0 ) {
			$params[] = (int) $limit;
			$params[] = (int) $offset;
			$rows     = $wpdb->get_results( $wpdb->prepare( $sql . ' LIMIT %d OFFSET %d', $params ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		}
		foreach ( $rows as &$r ) {
			$d         = json_decode( $r['data'], true );
			$r['data'] = is_array( $d ) ? $d : array();
		}
		return $rows;
	}

	public static function count_entries( $form_id, array $args = array() ) {
		global $wpdb;
		list( $where, $params ) = self::entries_query( $form_id, $args );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::entries_table() . ' WHERE ' . $where, $params ) );
	}

	/** Sanitize an id list: positive unique ints only. */
	private static function clean_ids( array $ids ) {
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/** @return int rows changed. */
	public static function set_entries_status( $form_id, array $ids, $status ) {
		global $wpdb;
		$ids = self::clean_ids( $ids );
		if ( ! $ids || ! in_array( $status, array( 'unread', 'read' ), true ) ) {
			return 0;
		}
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql = $wpdb->prepare(
			'UPDATE ' . self::entries_table() . " SET status = %s WHERE form_id = %d AND id IN ($in)",
			array_merge( array( $status, (int) $form_id ), $ids )
		);
		return (int) $wpdb->query( $sql );
	}

	/** Deletes via delete_entry() so uploaded files go too. @return int rows deleted. */
	public static function delete_entries( $form_id, array $ids ) {
		global $wpdb;
		$ids = self::clean_ids( $ids );
		if ( ! $ids ) {
			return 0;
		}
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$owned = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . self::entries_table() . " WHERE form_id = %d AND id IN ($in)",
				array_merge( array( (int) $form_id ), $ids )
			)
		);
		$n = 0;
		foreach ( $owned as $id ) {
			self::delete_entry( (int) $id );
			$n++;
		}
		return $n;
	}

	public static function update_entry_notes( $id, $notes ) {
		global $wpdb;
		$wpdb->update( self::entries_table(), array( 'notes' => (string) $notes ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
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
