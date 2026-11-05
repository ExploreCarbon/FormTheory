<?php
defined( 'ABSPATH' ) || exit;

/** Retention purge + WordPress personal-data exporter/eraser/policy text. */
class FormTheory_Privacy {

	const PAGE_SIZE = 50;
	const MAX_DAYS  = 3650;
	const CRON_HOOK = 'formtheory_purge';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_purge' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_content' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::CRON_HOOK );
		}
	}

	public static function retention_days() {
		$s = get_option( 'formtheory_settings', array() );
		$d = is_array( $s ) && isset( $s['retention_days'] ) ? (int) $s['retention_days'] : 0;
		return max( 0, min( $d, self::MAX_DAYS ) );
	}

	public static function run_purge() {
		return FormTheory_DB::purge_older_than( self::retention_days() );
	}

	public static function register_exporter( $exporters ) {
		$exporters['formtheory'] = array(
			'exporter_friendly_name' => __( 'FormTheory entries', 'formtheory' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['formtheory'] = array(
			'eraser_friendly_name' => __( 'FormTheory entries', 'formtheory' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	public static function policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$days = self::retention_days();
		if ( $days > 0 ) {
			/* translators: %d: number of days */
			$ret = sprintf( __( 'Entries are automatically deleted after %d days.', 'formtheory' ), $days );
		} else {
			$ret = __( 'Entries are kept until an administrator deletes them.', 'formtheory' );
		}
		$text = '<p>' . esc_html__( 'When a visitor submits a FormTheory form, the site stores the submitted field data (including any uploaded files), the visitor\'s IP address, the WordPress user ID if they are logged in, and the submission date.', 'formtheory' ) . '</p>'
			. '<p>' . esc_html( $ret ) . '</p>';
		wp_add_privacy_policy_content( 'FormTheory', wp_kses_post( $text ) );
	}

	public static function export( $email, $page = 1 ) {
		$all    = FormTheory_DB::find_entries_by_email( (string) $email );
		$page   = max( 1, (int) $page );
		$offset = ( $page - 1 ) * self::PAGE_SIZE;
		$data   = array();
		foreach ( array_slice( $all, $offset, self::PAGE_SIZE ) as $e ) {
			$items = array(
				array( 'name' => __( 'Form ID', 'formtheory' ), 'value' => (string) $e['form_id'] ),
				array( 'name' => __( 'Submitted', 'formtheory' ), 'value' => (string) $e['created_at'] ),
				array( 'name' => __( 'IP address', 'formtheory' ), 'value' => (string) $e['ip'] ),
				array( 'name' => __( 'User ID', 'formtheory' ), 'value' => (string) $e['user_id'] ),
			);
			foreach ( $e['data'] as $k => $v ) {
				$items[] = array( 'name' => (string) $k, 'value' => self::value_text( $v ) );
			}
			$data[] = array(
				'group_id'    => 'formtheory',
				'group_label' => __( 'Form entries', 'formtheory' ),
				'item_id'     => 'entry-' . (int) $e['id'],
				'data'        => $items,
			);
		}
		return array(
			'data' => $data,
			'done' => $offset + self::PAGE_SIZE >= count( $all ),
		);
	}

	/** Erased rows drop out of the match set, so each call erases the first batch; $page is unused. */
	public static function erase( $email, $page = 1 ) {
		$all     = FormTheory_DB::find_entries_by_email( (string) $email );
		$removed = 0;
		foreach ( array_slice( $all, 0, self::PAGE_SIZE ) as $e ) {
			if ( FormTheory_DB::delete_entry( (int) $e['id'] ) ) {
				$removed++;
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => 0 === $removed || count( $all ) <= self::PAGE_SIZE,
		);
	}

	private static function value_text( $v ) {
		if ( is_array( $v ) ) {
			if ( isset( $v['name'] ) && is_scalar( $v['name'] ) ) {
				return (string) $v['name'];
			}
			if ( isset( $v['file'] ) && is_scalar( $v['file'] ) ) {
				return (string) $v['file'];
			}
			return implode( ', ', array_map( 'strval', array_filter( $v, 'is_scalar' ) ) );
		}
		return is_scalar( $v ) ? (string) $v : '';
	}
}
