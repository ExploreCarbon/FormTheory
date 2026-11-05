<?php
defined( 'ABSPATH' ) || exit;

class FormTheory_Admin {

	const CAP = 'manage_options';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_formtheory_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_formtheory_delete_form', array( $this, 'handle_delete_form' ) );
		add_action( 'admin_post_formtheory_delete_entry', array( $this, 'handle_delete_entry' ) );
		add_action( 'admin_post_formtheory_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_formtheory_file', array( $this, 'handle_file' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( 'FormTheory_Recaptcha', 'register_setting' ) );
	}

	public function menu() {
		add_menu_page( 'FormTheory', 'FormTheory', self::CAP, 'formtheory', array( $this, 'page_forms' ), 'dashicons-feedback', 30 );
		add_submenu_page( 'formtheory', 'Forms', 'Forms', self::CAP, 'formtheory', array( $this, 'page_forms' ) );
		add_submenu_page( 'formtheory', 'New Form', 'New Form', self::CAP, 'formtheory-edit', array( $this, 'page_edit' ) );
		add_submenu_page( 'formtheory', 'Entries', 'Entries', self::CAP, 'formtheory-entries', array( $this, 'page_entries' ) );
		add_submenu_page( 'formtheory', 'Settings', 'Settings', self::CAP, 'formtheory-settings', array( $this, 'page_settings' ) );
	}

	public function assets( $hook ) {
		if ( false === strpos( $hook, 'formtheory' ) ) {
			return;
		}
		wp_enqueue_style( 'formtheory-admin', FORMTHEORY_URL . 'assets/admin.css', array(), FORMTHEORY_VERSION );
		if ( false !== strpos( $hook, 'formtheory-edit' ) ) {
			wp_enqueue_script( 'formtheory-builder', FORMTHEORY_URL . 'assets/builder.js', array(), FORMTHEORY_VERSION, true );
		}
	}

	private function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formtheory' ), 403 );
		}
		check_admin_referer( $action );
	}

	private function admin_url( $page, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	private function action_url( $action, array $args = array() ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => $action ), $args ), admin_url( 'admin-post.php' ) ), $action );
	}

	/* ---------- Pages ---------- */

	public function page_forms() {
		$forms = FormTheory_DB::get_forms();
		echo '<div class="wrap"><h1 class="wp-heading-inline">Forms</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( $this->admin_url( 'formtheory-edit' ) ) . '">Add New</a>';
		$this->notice();
		echo '<table class="widefat striped ft-table"><thead><tr><th scope="col">Title</th><th scope="col">Shortcode</th><th scope="col">Entries</th><th scope="col">Updated</th><th scope="col"><span class="screen-reader-text">Actions</span></th></tr></thead><tbody>';
		if ( ! $forms ) {
			echo '<tr><td colspan="5">No forms yet.</td></tr>';
		}
		foreach ( $forms as $f ) {
			$id = (int) $f['id'];
			printf(
				'<tr><td><a href="%1$s"><strong>%2$s</strong></a></td><td><code>[formtheory id="%3$d"]</code></td><td><a href="%4$s">%5$d</a></td><td>%6$s</td><td><a href="%1$s">Edit</a> | <a href="%4$s">Entries</a> | <a class="ft-danger" href="%7$s" onclick="return confirm(\'Delete form and all its entries?\');">Delete<span class="screen-reader-text"> %2$s</span></a></td></tr>',
				esc_url( $this->admin_url( 'formtheory-edit', array( 'id' => $id ) ) ),
				esc_html( $f['title'] ? $f['title'] : '(untitled)' ),
				$id,
				esc_url( $this->admin_url( 'formtheory-entries', array( 'form' => $id ) ) ),
				FormTheory_DB::count_entries( $id ),
				esc_html( $f['updated_at'] ),
				esc_url( $this->action_url( 'formtheory_delete_form', array( 'id' => $id ) ) )
			);
		}
		echo '</tbody></table></div>';
	}

	public function page_edit() {
		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$form = $id ? FormTheory_DB::get_form( $id ) : null;
		if ( $id && ! $form ) {
			wp_die( esc_html__( 'Form not found.', 'formtheory' ) );
		}
		$title  = $form ? $form['title'] : '';
		$config = $form ? $form['config'] : FormTheory_Fields::migrate_config( FormTheory_DB::default_config() );
		echo '<div class="wrap"><h1>' . ( $id ? 'Edit Form' : 'New Form' ) . '</h1>';
		$this->notice();
		if ( $id ) {
			echo '<p>Embed: <code>[formtheory id="' . (int) $id . '"]</code></p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="ft-builder-form">';
		wp_nonce_field( 'formtheory_save' );
		echo '<input type="hidden" name="action" value="formtheory_save">';
		echo '<input type="hidden" name="id" value="' . (int) $id . '">';
		echo '<p><label for="ft-title"><strong>Form title</strong></label><br><input type="text" class="regular-text" id="ft-title" name="title" required value="' . esc_attr( $title ) . '"></p>';
		echo '<textarea hidden name="config" id="ft-config">' . esc_textarea( wp_json_encode( $config ) ) . '</textarea>';
		echo '<div id="ft-builder" data-types="' . esc_attr( wp_json_encode( FormTheory_Fields::TYPES ) ) . '"><noscript>The form builder requires JavaScript.</noscript></div>';
		submit_button( 'Save Form' );
		echo '</form></div>';
	}

	public function page_entries() {
		$form_id = isset( $_GET['form'] ) ? absint( $_GET['form'] ) : 0;
		$entry   = isset( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0;
		$forms   = FormTheory_DB::get_forms();
		echo '<div class="wrap"><h1>Entries</h1>';
		$this->notice();

		if ( $entry ) {
			$this->render_entry( $entry );
			echo '</div>';
			return;
		}

		echo '<form method="get"><input type="hidden" name="page" value="formtheory-entries"><label for="ft-form-pick">Form </label><select id="ft-form-pick" name="form">';
		foreach ( $forms as $f ) {
			if ( ! $form_id ) {
				$form_id = (int) $f['id'];
			}
			printf( '<option value="%d"%s>%s</option>', (int) $f['id'], selected( $form_id, (int) $f['id'], false ), esc_html( $f['title'] ) );
		}
		echo '</select> <button class="button">View</button></form>';

		$form = $form_id ? FormTheory_DB::get_form( $form_id ) : null;
		if ( ! $form ) {
			echo '<p>No forms.</p></div>';
			return;
		}
		echo '<p><a class="button" href="' . esc_url( $this->action_url( 'formtheory_export', array( 'form' => $form_id ) ) ) . '">Export CSV</a></p>';

		$per   = 50;
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$total = FormTheory_DB::count_entries( $form_id );
		$rows  = FormTheory_DB::get_entries( $form_id, $per, ( $paged - 1 ) * $per );
		$cols  = array_slice( $this->data_fields( $form ), 0, 4 );

		echo '<table class="widefat striped ft-table"><thead><tr><th scope="col">#</th><th scope="col">Date</th>';
		foreach ( $cols as $c ) {
			echo '<th scope="col">' . esc_html( $c['label'] ) . '</th>';
		}
		echo '<th scope="col"><span class="screen-reader-text">Actions</span></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="' . ( count( $cols ) + 3 ) . '">No entries.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$view = $this->admin_url( 'formtheory-entries', array( 'form' => $form_id, 'entry' => (int) $r['id'] ) );
			echo '<tr><td><a href="' . esc_url( $view ) . '">' . (int) $r['id'] . '</a></td><td>' . esc_html( $r['created_at'] ) . '</td>';
			foreach ( $cols as $c ) {
				echo '<td>' . esc_html( wp_trim_words( $this->plain_value( $r['data'][ $c['id'] ] ?? '' ), 12 ) ) . '</td>';
			}
			echo '<td><a href="' . esc_url( $view ) . '">View<span class="screen-reader-text"> entry ' . (int) $r['id'] . '</span></a></td></tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<nav class="ft-pager" aria-label="Entries pages">';
			echo paginate_links( array( // phpcs:ignore WordPress.Security.EscapeOutput -- core-escaped.
				'base'    => add_query_arg( 'paged', '%#%' ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			) );
			echo '</nav>';
		}
		echo '</div>';
	}

	private function render_entry( $entry_id ) {
		$entry = FormTheory_DB::get_entry( $entry_id );
		$form  = $entry ? FormTheory_DB::get_form( $entry['form_id'] ) : null;
		if ( ! $entry || ! $form ) {
			echo '<p>Entry not found.</p>';
			return;
		}
		$back = $this->admin_url( 'formtheory-entries', array( 'form' => (int) $form['id'] ) );
		echo '<p><a href="' . esc_url( $back ) . '">&larr; Back to ' . esc_html( $form['title'] ) . '</a></p>';
		echo '<h2>Entry #' . (int) $entry['id'] . '</h2><table class="widefat ft-entry"><tbody>';
		echo '<tr><th scope="row">Submitted</th><td>' . esc_html( $entry['created_at'] ) . '</td></tr>';
		echo '<tr><th scope="row">IP</th><td>' . esc_html( $entry['ip'] ) . '</td></tr>';
		foreach ( $this->data_fields( $form ) as $f ) {
			$val = $entry['data'][ $f['id'] ] ?? '';
			echo '<tr><th scope="row">' . esc_html( $f['label'] ) . '</th><td>';
			if ( is_array( $val ) && isset( $val['file'] ) ) {
				$url = $this->action_url( 'formtheory_file', array( 'entry' => (int) $entry['id'], 'field' => $f['id'] ) );
				echo '<a href="' . esc_url( $url ) . '">' . esc_html( $val['name'] ) . '</a>';
			} else {
				echo nl2br( esc_html( $this->plain_value( $val ) ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><a class="button ft-danger" href="' . esc_url( $this->action_url( 'formtheory_delete_entry', array( 'id' => (int) $entry['id'] ) ) ) . '" onclick="return confirm(\'Delete this entry?\');">Delete entry</a></p>';
	}

	public function register_settings() {
		register_setting( 'formtheory_settings', 'formtheory_settings', array(
			'type'              => 'array',
			'sanitize_callback' => function ( $in ) {
				$in = is_array( $in ) ? $in : array();
				return array(
					'turnstile_site'   => sanitize_text_field( $in['turnstile_site'] ?? '' ),
					'turnstile_secret' => sanitize_text_field( $in['turnstile_secret'] ?? '' ),
				);
			},
		) );
	}

	public function page_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s = FormTheory_Spam::settings();
		echo '<div class="wrap"><h1>FormTheory Settings</h1><form method="post" action="options.php">';
		settings_fields( 'formtheory_settings' );
		FormTheory_Recaptcha::fields();
		echo '<h2>Cloudflare Turnstile</h2><p>Leave both blank to disable. A signed time-trap and honeypot are always on.</p>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="ft-ts-site">Site key</label></th><td><input type="text" class="regular-text" id="ft-ts-site" name="formtheory_settings[turnstile_site]" value="' . esc_attr( $s['turnstile_site'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="ft-ts-secret">Secret key</label></th><td><input type="password" class="regular-text" id="ft-ts-secret" name="formtheory_settings[turnstile_secret]" value="' . esc_attr( $s['turnstile_secret'] ) . '" autocomplete="off"></td></tr>';
		echo '</table>';
		submit_button();
		echo '</form></div>';
	}

	private function data_fields( $form ) {
		return FormTheory_Fields::data_fields( $form['config']['fields'] );
	}

	public function plain_value( $val ) {
		if ( is_array( $val ) ) {
			return isset( $val['file'] ) ? (string) $val['name'] : implode( ', ', array_map( 'strval', $val ) );
		}
		return (string) $val;
	}

	private function notice() {
		$msg = isset( $_GET['ft_msg'] ) ? sanitize_key( $_GET['ft_msg'] ) : '';
		$map = array(
			'saved'   => 'Form saved.',
			'deleted' => 'Deleted.',
		);
		if ( isset( $map[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible" role="status"><p>' . esc_html( $map[ $msg ] ) . '</p></div>';
		}
	}

	/* ---------- Handlers ---------- */

	public function handle_save() {
		$this->guard( 'formtheory_save' );
		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$raw   = isset( $_POST['config'] ) ? json_decode( wp_unslash( $_POST['config'] ), true ) : array(); // phpcs:ignore -- sanitized below.
		$cfg   = FormTheory_Fields::sanitize_config( $raw );
		$id    = FormTheory_DB::save_form( $id, $title, $cfg );
		wp_safe_redirect( $this->admin_url( 'formtheory-edit', array( 'id' => $id, 'ft_msg' => 'saved' ) ) );
		exit;
	}

	public function handle_delete_form() {
		$this->guard( 'formtheory_delete_form' );
		FormTheory_DB::delete_form( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		wp_safe_redirect( $this->admin_url( 'formtheory', array( 'ft_msg' => 'deleted' ) ) );
		exit;
	}

	public function handle_delete_entry() {
		$this->guard( 'formtheory_delete_entry' );
		$id    = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$entry = FormTheory_DB::get_entry( $id );
		FormTheory_DB::delete_entry( $id );
		wp_safe_redirect( $this->admin_url( 'formtheory-entries', array( 'form' => $entry ? (int) $entry['form_id'] : 0, 'ft_msg' => 'deleted' ) ) );
		exit;
	}

	/** Neutralize spreadsheet formula injection. */
	private function csv_cell( $v ) {
		$v = $this->plain_value( $v );
		if ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$v = "'" . $v;
		}
		return $v;
	}

	public function handle_export() {
		$this->guard( 'formtheory_export' );
		$form_id = isset( $_GET['form'] ) ? absint( $_GET['form'] ) : 0;
		$form    = FormTheory_DB::get_form( $form_id );
		if ( ! $form ) {
			wp_die( 'Form not found.' );
		}
		$fields = $this->data_fields( $form );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="formtheory-' . $form_id . '-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		$head = array( 'Entry ID', 'Date', 'IP' );
		foreach ( $fields as $f ) {
			$head[] = $this->csv_cell( $f['label'] );
		}
		fputcsv( $out, $head );
		foreach ( FormTheory_DB::get_entries( $form_id, 0 ) as $r ) {
			$line = array( $r['id'], $r['created_at'], $r['ip'] );
			foreach ( $fields as $f ) {
				$line[] = $this->csv_cell( $r['data'][ $f['id'] ] ?? '' );
			}
			fputcsv( $out, $line );
		}
		fclose( $out );
		exit;
	}

	public function handle_file() {
		$this->guard( 'formtheory_file' );
		$entry = FormTheory_DB::get_entry( isset( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0 );
		$field = isset( $_GET['field'] ) ? sanitize_key( $_GET['field'] ) : '';
		$val   = $entry['data'][ $field ] ?? null;
		if ( ! is_array( $val ) || empty( $val['file'] ) ) {
			wp_die( 'File not found.', 404 );
		}
		$path = FormTheory_DB::upload_dir() . '/' . basename( $val['file'] );
		if ( ! is_file( $path ) ) {
			wp_die( 'File not found.', 404 );
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( array( '"', "\r", "\n" ), '', $val['name'] ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}
}
