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

	public static function webhook_log_table() {
		global $wpdb;
		return $wpdb->prefix . 'formtheory_webhook_log';
	}

	public static function drafts_table() {
		global $wpdb;
		return $wpdb->prefix . 'formtheory_drafts';
	}

	/**
	 * Remove all plugin data. Only called from uninstall.php after the opt-in flag check.
	 * Single-site scope: multisite per-blog cleanup is not handled.
	 */
	public static function uninstall() {
		global $wpdb;
		foreach ( array( self::forms_table(), self::entries_table(), self::webhook_log_table(), self::drafts_table() ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS $table" );
		}
		foreach ( array( 'formtheory_settings', 'formtheory_recaptcha', 'formtheory_db_version' ) as $opt ) {
			delete_option( $opt );
		}
		foreach ( array( 'formtheory_send_webhook', 'formtheory_deliver_webhook', 'formtheory_purge' ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
		self::remove_dir( self::upload_dir() );
	}

	/** Recursively delete a directory. Refuses unless its basename is exactly formtheory-private; never follows symlinks. */
	public static function remove_dir( $dir ) {
		$dir = rtrim( (string) $dir, '/\\' );
		if ( 'formtheory-private' !== basename( $dir ) || is_link( $dir ) || ! is_dir( $dir ) ) {
			return;
		}
		self::remove_tree( $dir );
	}

	private static function remove_tree( $dir ) {
		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_link( $path ) ) {
				continue;
			}
			if ( is_dir( $path ) ) {
				self::remove_tree( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
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

		$log = self::webhook_log_table();
		dbDelta( "CREATE TABLE $log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			entry_id bigint(20) unsigned NOT NULL DEFAULT 0,
			url varchar(2048) NOT NULL DEFAULT '',
			request longtext NOT NULL,
			status varchar(10) NOT NULL DEFAULT 'pending',
			http_code smallint(5) unsigned NOT NULL DEFAULT 0,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			error varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY status (status),
			KEY entry_id (entry_id)
		) $charset;" );

		$drafts = self::drafts_table();
		dbDelta( "CREATE TABLE $drafts (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			token_hash char(64) NOT NULL,
			data longtext NOT NULL,
			page tinyint(3) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY expires_at (expires_at),
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
			'submit_label'  => __( 'Submit', 'formtheory' ),
			'confirmation'  => __( 'Thanks! Your submission was received.', 'formtheory' ),
			'notify_to'     => get_option( 'admin_email' ),
			'notify_subject'=> __( 'New form submission', 'formtheory' ),
			'ajax'              => true,
			'confirmation_type' => 'message',
			'confirmation_page' => 0,
			'confirmation_url'  => '',
			'save_resume'       => false,
			'store_entries'     => true,
			'auto_advance'      => false,
			'scoring'           => array( 'enabled' => false, 'threshold' => 80, 'overall_page' => 0, 'pages' => array() ),
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

	public static function duplicate_form( $id ) {
		$form = self::get_form( (int) $id );
		if ( ! $form ) {
			return 0;
		}
		/* translators: %s: original form title */
		$title = sprintf( __( '%s (copy)', 'formtheory' ), $form['title'] );
		return self::save_form( 0, $title, $form['config'] );
	}

	public static function delete_form( $id ) {
		global $wpdb;
		foreach ( self::get_entries( $id, 0, 0 ) as $e ) {
			self::delete_entry( $e['id'] );
		}
		FormTheory_Drafts::delete_for_form( (int) $id );
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
				'status'     => 'unread',
				'notes'      => '',
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
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
			// data is stored as JSON: match the JSON-escaped form of the term.
			$encoded = wp_json_encode( $search );
			if ( false === $encoded ) {
				// Invalid UTF-8 cannot match any stored JSON: match nothing.
				$where .= ' AND 1=0';
			} else {
				$where   .= ' AND data LIKE %s';
				$params[] = '%' . $wpdb->esc_like( substr( $encoded, 1, -1 ) ) . '%';
			}
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
		return array_values( array_unique( array_filter( array_map( 'absint', array_filter( $ids, 'is_scalar' ) ) ) ) );
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
				// Uploaded files ('file') and drawn signatures ('sig') both live in the private dir.
				$stored = is_array( $val ) ? ( $val['file'] ?? ( $val['sig'] ?? null ) ) : null;
				if ( is_string( $stored ) && '' !== $stored ) {
					$path = self::upload_dir() . '/' . basename( $stored );
					if ( is_file( $path ) ) {
						wp_delete_file( $path );
					}
				}
			}
		}
		// Webhook log rows hold a full payload copy; erase them with the entry.
		$wpdb->delete( self::webhook_log_table(), array( 'entry_id' => (int) $id ), array( '%d' ) );
		return (bool) $wpdb->delete( self::entries_table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Single place that computes the retention cutoff (site-local wall time,
	 * matching insert_entry()'s current_time( 'mysql' )). Extend here for other tables.
	 */
	public static function retention_cutoff( $days ) {
		return gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - (int) $days * 86400 );
	}

	/** Deletes entries older than $days (files included). $days <= 0 = keep forever, no query. @return int */
	public static function purge_older_than( $days ) {
		global $wpdb;
		$days = (int) $days;
		if ( $days <= 0 ) {
			return 0;
		}
		$cutoff = self::retention_cutoff( $days );
		$ids    = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::entries_table() . ' WHERE created_at < %s', $cutoff ) );
		$n      = 0;
		foreach ( (array) $ids as $id ) {
			self::delete_entry( (int) $id );
			$n++;
		}
		// Webhook log rows carry payload copies, so they follow the same retention window.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::webhook_log_table() . ' WHERE created_at < %s', $cutoff ) );
		return $n;
	}

	/** @param array $request { body, headers } incl. signature header; never the secret. @return int log id */
	public static function log_webhook( $form_id, $entry_id, $url, array $request ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert(
			self::webhook_log_table(),
			array(
				'form_id'    => (int) $form_id,
				'entry_id'   => (int) $entry_id,
				'url'        => (string) $url,
				'request'    => wp_json_encode( $request ),
				'status'     => 'pending',
				'http_code'  => 0,
				'attempts'   => 0,
				'error'      => '',
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/** Only status, http_code, attempts and error are updatable. */
	public static function update_webhook_log( $id, array $fields ) {
		global $wpdb;
		$formats = array( 'status' => '%s', 'http_code' => '%d', 'attempts' => '%d', 'error' => '%s' );
		$data    = array_intersect_key( $fields, $formats );
		if ( ! $data ) {
			return;
		}
		$fmt                = array_values( array_intersect_key( $formats, $data ) );
		$data['updated_at'] = current_time( 'mysql' );
		$fmt[]              = '%s';
		$wpdb->update( self::webhook_log_table(), $data, array( 'id' => (int) $id ), $fmt, array( '%d' ) );
	}

	/**
	 * Atomically claim a pending row for one delivery attempt (bumps attempts so the UPDATE always changes the row).
	 * @return bool true only for the single caller that won the claim.
	 */
	public static function claim_webhook( $id, $attempts ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			'UPDATE ' . self::webhook_log_table() . " SET attempts = attempts + 1, updated_at = %s WHERE id = %d AND status = 'pending' AND attempts = %d",
			current_time( 'mysql' ),
			(int) $id,
			(int) $attempts
		);
		return 1 === (int) $wpdb->query( $sql );
	}

	/** Atomically move a failed row back to pending. @return bool */
	public static function reset_failed_webhook( $id ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			'UPDATE ' . self::webhook_log_table() . " SET status = 'pending', attempts = 0, http_code = 0, error = '', updated_at = %s WHERE id = %d AND status = 'failed'",
			current_time( 'mysql' ),
			(int) $id
		);
		return 1 === (int) $wpdb->query( $sql );
	}

	public static function get_webhook_log( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::webhook_log_table() . ' WHERE id = %d', (int) $id ), ARRAY_A );
		return $row ? $row : null;
	}

	private static function webhook_where( $status ) {
		return in_array( $status, array( 'pending', 'ok', 'failed' ), true ) ? array( ' WHERE status = %s', array( $status ) ) : array( '', array() );
	}

	public static function list_webhook_log( $limit, $offset, $status = '' ) {
		global $wpdb;
		list( $where, $params ) = self::webhook_where( (string) $status );
		$params[]               = max( 1, (int) $limit );
		$params[]               = max( 0, (int) $offset );
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::webhook_log_table() . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d', $params ),
			ARRAY_A
		);
	}

	public static function count_webhook_log( $status = '' ) {
		global $wpdb;
		list( $where, $params ) = self::webhook_where( (string) $status );
		$sql                    = 'SELECT COUNT(*) FROM ' . self::webhook_log_table() . $where;
		return (int) $wpdb->get_var( $params ? $wpdb->prepare( $sql, $params ) : $sql );
	}

	/** Entries whose data has an exact (case-insensitive) scalar value equal to $email. */
	public static function find_entries_by_email( $email ) {
		global $wpdb;
		$email = self::lower( trim( (string) $email ) );
		if ( '' === $email ) {
			return array();
		}
		$table = self::entries_table();
		$out   = array();
		// Non-ASCII is stored as \uXXXX with case-variant hex, so a LIKE prefilter could miss; scan in id batches instead.
		$ascii = ! preg_match( '/[^\x20-\x7e]/', $email );
		$like  = $ascii ? '%' . $wpdb->esc_like( substr( wp_json_encode( $email ), 1, -1 ) ) . '%' : '';
		$last  = 0;
		do {
			if ( $ascii ) {
				$sql = $wpdb->prepare( "SELECT * FROM $table WHERE data LIKE %s AND id > %d ORDER BY id ASC LIMIT 500", $like, $last );
			} else {
				$sql = $wpdb->prepare( "SELECT * FROM $table WHERE id > %d ORDER BY id ASC LIMIT 500", $last );
			}
			$rows = (array) $wpdb->get_results( $sql, ARRAY_A );
			foreach ( $rows as $r ) {
				$last = max( $last, (int) $r['id'] );
				$d    = json_decode( $r['data'], true );
				$d    = is_array( $d ) ? $d : array();
				foreach ( $d as $v ) {
					if ( is_scalar( $v ) && self::lower( trim( (string) $v ) ) === $email ) {
						$r['data'] = $d;
						$out[]     = $r;
						break;
					}
				}
			}
		} while ( count( $rows ) >= 500 );
		return $out;
	}

	private static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
	}

	/** REMOTE_ADDR only; proxy headers are client-controlled and never trusted. */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
