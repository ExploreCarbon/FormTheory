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
		add_action( 'admin_post_formtheory_bulk', array( $this, 'handle_bulk' ) );
		add_action( 'admin_post_formtheory_note', array( $this, 'handle_note' ) );
		add_action( 'admin_post_formtheory_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_formtheory_duplicate', array( $this, 'handle_duplicate' ) );
		add_action( 'admin_post_formtheory_export_form', array( $this, 'handle_export_form' ) );
		add_action( 'admin_post_formtheory_import', array( $this, 'handle_import' ) );
		add_action( 'admin_post_formtheory_file', array( $this, 'handle_file' ) );
		add_action( 'admin_post_formtheory_webhook_retry', array( $this, 'handle_webhook_retry' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( 'FormTheory_Recaptcha', 'register_setting' ) );
	}

	public function menu() {
		add_menu_page( 'FormTheory', 'FormTheory', self::CAP, 'formtheory', array( $this, 'page_forms' ), 'dashicons-feedback', 30 );
		add_submenu_page( 'formtheory', __( 'Forms', 'formtheory' ), __( 'Forms', 'formtheory' ), self::CAP, 'formtheory', array( $this, 'page_forms' ) );
		add_submenu_page( 'formtheory', __( 'New Form', 'formtheory' ), __( 'New Form', 'formtheory' ), self::CAP, 'formtheory-edit', array( $this, 'page_edit' ) );
		add_submenu_page( 'formtheory', __( 'Entries', 'formtheory' ), __( 'Entries', 'formtheory' ), self::CAP, 'formtheory-entries', array( $this, 'page_entries' ) );
		add_submenu_page( 'formtheory', __( 'Webhook Log', 'formtheory' ), __( 'Webhook Log', 'formtheory' ), self::CAP, 'formtheory-webhooks', array( $this, 'page_webhooks' ) );
		add_submenu_page( 'formtheory', __( 'Settings', 'formtheory' ), __( 'Settings', 'formtheory' ), self::CAP, 'formtheory-settings', array( $this, 'page_settings' ) );
	}

	public function assets( $hook ) {
		if ( false === strpos( $hook, 'formtheory' ) ) {
			return;
		}
		wp_enqueue_style( 'formtheory-admin', FORMTHEORY_URL . 'assets/admin.css', array(), FORMTHEORY_VERSION );
		wp_enqueue_script( 'formtheory-admin', FORMTHEORY_URL . 'assets/admin.js', array(), FORMTHEORY_VERSION, true );
		if ( false !== strpos( $hook, 'formtheory-edit' ) ) {
			wp_enqueue_script( 'formtheory-builder', FORMTHEORY_URL . 'assets/builder.js', array(), FORMTHEORY_VERSION, true );
			wp_localize_script( 'formtheory-builder', 'FormTheoryBuilderI18n', self::builder_i18n() );
		}
	}

	/**
	 * Strings for assets/builder.js (read as FormTheoryBuilderI18n.key).
	 * Printf-style placeholders (%s, %d, %1$s) are substituted client-side.
	 */
	public static function builder_i18n() {
		return array(
			'types'            => array(
				'text'     => __( 'Text', 'formtheory' ),
				'email'    => __( 'Email', 'formtheory' ),
				'phone'    => __( 'Phone', 'formtheory' ),
				'number'   => __( 'Number', 'formtheory' ),
				'date'     => __( 'Date', 'formtheory' ),
				'textarea' => __( 'Paragraph', 'formtheory' ),
				'select'   => __( 'Dropdown', 'formtheory' ),
				'radio'    => __( 'Radio buttons', 'formtheory' ),
				'checkbox' => __( 'Checkboxes', 'formtheory' ),
				'file'     => __( 'File upload', 'formtheory' ),
				'page'     => __( 'Page break', 'formtheory' ),
				'hidden'   => __( 'Hidden', 'formtheory' ),
				'consent'  => __( 'Consent', 'formtheory' ),
				'html'     => __( 'HTML block', 'formtheory' ),
				'calc'     => __( 'Calculation', 'formtheory' ),
				'rating'   => __( 'Rating', 'formtheory' ),
				'address'  => __( 'Address', 'formtheory' ),
				'signature' => __( 'Signature', 'formtheory' ),
				'repeater' => __( 'Repeater', 'formtheory' ),
			),
			'ops'              => array(
				'is'       => __( 'is', 'formtheory' ),
				'isnot'    => __( 'is not', 'formtheory' ),
				'contains' => __( 'contains', 'formtheory' ),
				'gt'       => __( 'greater than', 'formtheory' ),
				'lt'       => __( 'less than', 'formtheory' ),
				'empty'    => __( 'is empty', 'formtheory' ),
				'notempty' => __( 'is not empty', 'formtheory' ),
			),
			/* translators: %d: new position of the field in the list. */
			'moved_to'         => __( 'Moved to position %d', 'formtheory' ),
			'no_label'         => __( '(no label)', 'formtheory' ),
			'enable_cond'      => __( 'Enable conditional logic', 'formtheory' ),
			'add_fields_first' => __( 'Add other fields first.', 'formtheory' ),
			/* translators: 1: Show or Hide (dropdown), 2: all or any (dropdown). */
			'cond_sentence'    => __( '%1$s this field if %2$s of these match:', 'formtheory' ),
			'show'             => __( 'Show', 'formtheory' ),
			'hide'             => __( 'Hide', 'formtheory' ),
			'all'              => __( 'all', 'formtheory' ),
			'any'              => __( 'any', 'formtheory' ),
			/* translators: %s: all or any (dropdown). */
			'when_sentence'    => __( 'When %s of these match:', 'formtheory' ),
			'value'            => __( 'Value', 'formtheory' ),
			'field'            => __( 'Field', 'formtheory' ),
			'operator'         => __( 'Operator', 'formtheory' ),
			'remove'           => __( 'Remove', 'formtheory' ),
			/* translators: %d: rule number. */
			'remove_rule'      => __( 'Remove rule %d', 'formtheory' ),
			'add_rule'         => __( 'Add rule', 'formtheory' ),
			/* translators: %s: list of merge tags. Do not translate the tags. */
			'merge_tags'       => __( 'Merge tags: %s', 'formtheory' ),
			'formula'          => __( 'Formula', 'formtheory' ),
			/* translators: %s: list of field references such as {field:qty}. Do not translate the references. */
			'formula_hint'     => __( 'Use numbers, + - * /, parentheses and field references: %s', 'formtheory' ),
			'formula_no_refs'  => __( 'Use numbers, + - * / and parentheses. Add fields above this one to reference them.', 'formtheory' ),
			'decimals'         => __( 'Decimal places', 'formtheory' ),
			'rating_max'       => __( 'Number of stars (3-10)', 'formtheory' ),
			'address_parts'    => __( 'Address parts to show', 'formtheory' ),
			'country_default'  => __( 'Default country (optional)', 'formtheory' ),
			'part_street'      => __( 'Street address', 'formtheory' ),
			'part_street2'     => __( 'Apartment, suite, etc.', 'formtheory' ),
			'part_city'        => __( 'City', 'formtheory' ),
			'part_state'       => __( 'State / Province', 'formtheory' ),
			'part_zip'         => __( 'ZIP / Postal code', 'formtheory' ),
			'part_country'     => __( 'Country', 'formtheory' ),
			'repeater_columns' => __( 'Columns (1-6)', 'formtheory' ),
			/* translators: %d: column number. */
			'column_n'         => __( 'Column %d', 'formtheory' ),
			/* translators: %d: column number. */
			'remove_column_n'  => __( 'Remove column %d', 'formtheory' ),
			'column_label'     => __( 'Column label', 'formtheory' ),
			'column_type'      => __( 'Column type', 'formtheory' ),
			'column_choices'   => __( 'Dropdown choices (one per line)', 'formtheory' ),
			'add_column'       => __( 'Add column', 'formtheory' ),
			'column_added'     => __( 'Column added', 'formtheory' ),
			'column_removed'   => __( 'Column removed', 'formtheory' ),
			'min_rows_label'   => __( 'Minimum rows (0-20)', 'formtheory' ),
			'max_rows_label'   => __( 'Maximum rows (1-20)', 'formtheory' ),
			'add_row_label'    => __( 'Add button text', 'formtheory' ),
			'prefix'           => __( 'Prefix (for example $)', 'formtheory' ),
			'suffix'           => __( 'Suffix (for example kg)', 'formtheory' ),
			'notifications'    => __( 'Notifications', 'formtheory' ),
			/* translators: %d: notification number. */
			'notification_n'   => __( 'Notification %d', 'formtheory' ),
			/* translators: %d: notification number. */
			'remove_notif_n'   => __( 'Remove notification %d', 'formtheory' ),
			'notif_removed'    => __( 'Notification removed', 'formtheory' ),
			'add_notif'        => __( 'Add notification', 'formtheory' ),
			'notif_added'      => __( 'Notification added', 'formtheory' ),
			'webhooks'         => __( 'Webhooks', 'formtheory' ),
			/* translators: %d: webhook number. */
			'webhook_n'        => __( 'Webhook %d', 'formtheory' ),
			/* translators: %d: webhook number. */
			'remove_hook_n'    => __( 'Remove webhook %d', 'formtheory' ),
			'hook_removed'     => __( 'Webhook removed', 'formtheory' ),
			'add_hook'         => __( 'Add webhook', 'formtheory' ),
			'hook_added'       => __( 'Webhook added', 'formtheory' ),
			/* translators: Default email subject. Keep {form_title} unchanged. */
			'default_subject'  => __( 'New submission: {form_title}', 'formtheory' ),
			/* translators: Do not translate {field:ID}. */
			'send_to'          => __( 'Send to (emails or {field:ID}, comma separated)', 'formtheory' ),
			'subject'          => __( 'Subject', 'formtheory' ),
			'message'          => __( 'Message', 'formtheory' ),
			/* translators: Do not translate {field:ID}. */
			'reply_to'         => __( 'Reply-To (email or {field:ID})', 'formtheory' ),
			'attach_files'     => __( 'Attach uploaded files', 'formtheory' ),
			'only_send_when'   => __( 'Only send when conditions match', 'formtheory' ),
			'endpoint_url'     => __( 'Endpoint URL (https only)', 'formtheory' ),
			'format'           => __( 'Format', 'formtheory' ),
			'form_encoded'     => __( 'Form-encoded', 'formtheory' ),
			'signing_secret'   => __( 'Signing secret (optional, sent as X-FormTheory-Signature)', 'formtheory' ),
			/* translators: 1: field type label, 2: field label. */
			'card_title'       => __( '%1$s: %2$s', 'formtheory' ),
			'page_title'       => __( 'Page title', 'formtheory' ),
			'label'            => __( 'Label', 'formtheory' ),
			/* translators: Do not translate {query:param}. */
			'default_value'    => __( 'Default value (use {query:param} to fill from URL)', 'formtheory' ),
			'consent_text'     => __( 'Consent text (HTML links allowed)', 'formtheory' ),
			'html_content'     => __( 'HTML content', 'formtheory' ),
			'required'         => __( 'Required', 'formtheory' ),
			'placeholder'      => __( 'Placeholder', 'formtheory' ),
			'help_text'        => __( 'Help text', 'formtheory' ),
			'choices'          => __( 'Choices (one per line)', 'formtheory' ),
			'allowed_ext'      => __( 'Allowed extensions (comma separated)', 'formtheory' ),
			'max_size'         => __( 'Max size (MB)', 'formtheory' ),
			/* translators: %s: field title. */
			'move_up'          => __( 'Move %s up', 'formtheory' ),
			/* translators: %s: field title. */
			'move_down'        => __( 'Move %s down', 'formtheory' ),
			/* translators: %s: field title. */
			'duplicate_field'  => __( 'Duplicate %s', 'formtheory' ),
			/* translators: %s: field title. */
			'delete_field'     => __( 'Delete %s', 'formtheory' ),
			'up'               => __( 'Up', 'formtheory' ),
			'down'             => __( 'Down', 'formtheory' ),
			'duplicate'        => __( 'Duplicate', 'formtheory' ),
			'delete'           => __( 'Delete', 'formtheory' ),
			'field_duplicated' => __( 'Field duplicated', 'formtheory' ),
			'confirm_delete'   => __( 'Delete this field?', 'formtheory' ),
			'field_deleted'    => __( 'Field deleted', 'formtheory' ),
			'add_field'        => __( 'Add field', 'formtheory' ),
			/* translators: %s: field type label. */
			'field_added'      => __( '%s field added', 'formtheory' ),
			'option_1'         => __( 'Option 1', 'formtheory' ),
			'option_2'         => __( 'Option 2', 'formtheory' ),
			'no_fields'        => __( 'No fields yet. Use the buttons above to add one.', 'formtheory' ),
			'settings'         => __( 'Settings', 'formtheory' ),
			'submit_text'      => __( 'Submit button text', 'formtheory' ),
			'confirmation_msg' => __( 'Confirmation message', 'formtheory' ),
			'ajax'             => __( 'Submit without page reload (AJAX)', 'formtheory' ),
			'after_submit'     => __( 'After submit', 'formtheory' ),
			'show_message'     => __( 'Show message', 'formtheory' ),
			'go_to_page'       => __( 'Go to page (ID)', 'formtheory' ),
			'redirect_to_url'  => __( 'Redirect to URL', 'formtheory' ),
			'page_id'          => __( 'Page ID', 'formtheory' ),
			'redirect_url'     => __( 'Redirect URL (merge tags allowed in path/query)', 'formtheory' ),
		);
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
		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Forms', 'formtheory' ) . '</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( $this->admin_url( 'formtheory-edit' ) ) . '">' . esc_html__( 'Add New', 'formtheory' ) . '</a>';
		$this->notice();
		echo '<table class="widefat striped ft-table"><thead><tr><th scope="col">' . esc_html__( 'Title', 'formtheory' ) . '</th><th scope="col">' . esc_html__( 'Shortcode', 'formtheory' ) . '</th><th scope="col">' . esc_html__( 'Entries', 'formtheory' ) . '</th><th scope="col">' . esc_html__( 'Updated', 'formtheory' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'formtheory' ) . '</span></th></tr></thead><tbody>';
		if ( ! $forms ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No forms yet.', 'formtheory' ) . '</td></tr>';
		}
		foreach ( $forms as $f ) {
			$id = (int) $f['id'];
			printf(
				'<tr><td><a href="%1$s"><strong>%2$s</strong></a></td><td><code>[formtheory id="%3$d"]</code></td><td><a href="%4$s">%5$d</a></td><td>%6$s</td><td><a href="%1$s">%13$s<span class="screen-reader-text"> %2$s</span></a> | <a href="%4$s">%14$s<span class="screen-reader-text"> %2$s</span></a> | <a href="%9$s">%11$s<span class="screen-reader-text"> %2$s</span></a> | <a href="%10$s">%12$s<span class="screen-reader-text"> %2$s</span></a> | <a class="ft-danger" href="%7$s" data-ft-confirm="%8$s">%15$s<span class="screen-reader-text"> %2$s</span></a></td></tr>',
				esc_url( $this->admin_url( 'formtheory-edit', array( 'id' => $id ) ) ),
				esc_html( $f['title'] ? $f['title'] : __( '(untitled)', 'formtheory' ) ),
				$id,
				esc_url( $this->admin_url( 'formtheory-entries', array( 'form' => $id ) ) ),
				FormTheory_DB::count_entries( $id ),
				esc_html( $f['updated_at'] ),
				esc_url( $this->action_url( 'formtheory_delete_form', array( 'id' => $id ) ) ),
				esc_attr__( 'Delete form and all its entries?', 'formtheory' ),
				esc_url( $this->action_url( 'formtheory_duplicate', array( 'id' => $id ) ) ),
				esc_url( $this->action_url( 'formtheory_export_form', array( 'id' => $id ) ) ),
				esc_html__( 'Duplicate', 'formtheory' ),
				esc_html__( 'Export JSON', 'formtheory' ),
				esc_html__( 'Edit', 'formtheory' ),
				esc_html__( 'Entries', 'formtheory' ),
				esc_html__( 'Delete', 'formtheory' )
			);
		}
		echo '</tbody></table>';
		echo '<h2>' . esc_html__( 'Import form', 'formtheory' ) . '</h2>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ft-import">';
		wp_nonce_field( 'formtheory_import' );
		echo '<input type="hidden" name="action" value="formtheory_import">';
		echo '<p><label for="ft-import-file">' . esc_html__( 'JSON file (max 1 MB)', 'formtheory' ) . '</label><br><input type="file" id="ft-import-file" name="ft_import" accept=".json,application/json"></p>';
		echo '<p><label for="ft-import-json">' . esc_html__( 'Or paste JSON', 'formtheory' ) . '</label><br><textarea id="ft-import-json" name="ft_import_json" rows="6" class="large-text code"></textarea></p>';
		submit_button( __( 'Import', 'formtheory' ), 'secondary' );
		echo '</form></div>';
	}

	public function page_edit() {
		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$form = $id ? FormTheory_DB::get_form( $id ) : null;
		if ( $id && ! $form ) {
			wp_die( esc_html__( 'Form not found.', 'formtheory' ) );
		}
		$title  = $form ? $form['title'] : '';
		$config = $form ? $form['config'] : FormTheory_Fields::migrate_config( FormTheory_DB::default_config() );
		echo '<div class="wrap"><h1>' . ( $id ? esc_html__( 'Edit Form', 'formtheory' ) : esc_html__( 'New Form', 'formtheory' ) ) . '</h1>';
		$this->notice();
		if ( $id ) {
			printf(
				/* translators: %s: shortcode wrapped in a code element. */
				'<p>' . esc_html__( 'Embed: %s', 'formtheory' ) . '</p>',
				'<code>[formtheory id="' . (int) $id . '"]</code>'
			);
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="ft-builder-form">';
		wp_nonce_field( 'formtheory_save' );
		echo '<input type="hidden" name="action" value="formtheory_save">';
		echo '<input type="hidden" name="id" value="' . (int) $id . '">';
		echo '<p><label for="ft-title"><strong>' . esc_html__( 'Form title', 'formtheory' ) . '</strong></label><br><input type="text" class="regular-text" id="ft-title" name="title" required value="' . esc_attr( $title ) . '"></p>';
		echo '<textarea hidden name="config" id="ft-config">' . esc_textarea( wp_json_encode( $config ) ) . '</textarea>';
		echo '<div id="ft-builder" data-types="' . esc_attr( wp_json_encode( FormTheory_Fields::TYPES ) ) . '"><noscript>' . esc_html__( 'The form builder requires JavaScript.', 'formtheory' ) . '</noscript></div>';
		submit_button( __( 'Save Form', 'formtheory' ) );
		echo '</form></div>';
	}

	public function page_entries() {
		$form_id = isset( $_GET['form'] ) ? absint( $_GET['form'] ) : 0;
		$entry   = isset( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0;
		$forms   = FormTheory_DB::get_forms();
		echo '<div class="wrap"><h1>' . esc_html__( 'Entries', 'formtheory' ) . '</h1>';
		$this->notice();

		if ( $entry ) {
			$this->render_entry( $entry );
			echo '</div>';
			return;
		}

		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$status = in_array( $status, array( 'unread', 'read' ), true ) ? $status : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		echo '<form method="get" class="ft-filter"><input type="hidden" name="page" value="formtheory-entries"><input type="hidden" name="status" value="' . esc_attr( $status ) . '"><label for="ft-form-pick">' . esc_html__( 'Form', 'formtheory' ) . '</label><select id="ft-form-pick" name="form">';
		foreach ( $forms as $f ) {
			if ( ! $form_id ) {
				$form_id = (int) $f['id'];
			}
			printf( '<option value="%d"%s>%s</option>', (int) $f['id'], selected( $form_id, (int) $f['id'], false ), esc_html( $f['title'] ) );
		}
		echo '</select><label for="ft-search">' . esc_html__( 'Search', 'formtheory' ) . '</label><input type="search" id="ft-search" name="s" value="' . esc_attr( $search ) . '"> <button class="button">' . esc_html__( 'View', 'formtheory' ) . '</button></form>';

		$form = $form_id ? FormTheory_DB::get_form( $form_id ) : null;
		if ( ! $form ) {
			echo '<p>' . esc_html__( 'No forms.', 'formtheory' ) . '</p></div>';
			return;
		}

		$base = array( 'form' => $form_id );
		if ( '' !== $search ) {
			$base['s'] = $search;
		}
		$tabs = array(
			''       => __( 'All', 'formtheory' ),
			'unread' => __( 'Unread', 'formtheory' ),
			'read'   => __( 'Read', 'formtheory' ),
		);
		echo '<ul class="subsubsub ft-status">';
		$i = 0;
		foreach ( $tabs as $key => $label ) {
			$targs = $base;
			if ( '' !== $key ) {
				$targs['status'] = $key;
			}
			printf(
				'<li><a href="%s"%s>%s <span class="count">(%d)</span></a>%s</li>',
				esc_url( $this->admin_url( 'formtheory-entries', $targs ) ),
				$status === $key ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				FormTheory_DB::count_entries( $form_id, array( 'search' => $search, 'status' => $key ) ),
				++$i < count( $tabs ) ? ' |' : ''
			);
		}
		echo '</ul><p class="clear"><a class="button" href="' . esc_url( $this->action_url( 'formtheory_export', array( 'form' => $form_id ) ) ) . '">' . esc_html__( 'Export CSV', 'formtheory' ) . '</a></p>';

		$qargs = array( 'search' => $search, 'status' => $status );
		$per   = 50;
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$total = FormTheory_DB::count_entries( $form_id, $qargs );
		$rows  = FormTheory_DB::get_entries( $form_id, $per, ( $paged - 1 ) * $per, $qargs );
		$cols  = array_slice( $this->data_fields( $form ), 0, 4 );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="ft-bulk-form">';
		wp_nonce_field( 'formtheory_bulk' );
		echo '<input type="hidden" name="action" value="formtheory_bulk"><input type="hidden" name="form" value="' . (int) $form_id . '">';
		echo '<p class="ft-bulk"><label for="ft-bulk-action" class="screen-reader-text">' . esc_html__( 'Bulk action', 'formtheory' ) . '</label><select id="ft-bulk-action" name="ft_bulk"><option value="">' . esc_html__( 'Bulk actions', 'formtheory' ) . '</option><option value="read">' . esc_html__( 'Mark read', 'formtheory' ) . '</option><option value="unread">' . esc_html__( 'Mark unread', 'formtheory' ) . '</option><option value="delete">' . esc_html__( 'Delete', 'formtheory' ) . '</option></select> <button type="submit" class="button" data-ft-confirm-if="delete" data-ft-confirm="' . esc_attr__( 'Delete the selected entries?', 'formtheory' ) . '">' . esc_html__( 'Apply', 'formtheory' ) . '</button></p>';

		echo '<table class="widefat striped ft-table"><thead><tr><td class="check-column"><input type="checkbox" id="ft-select-all" aria-label="' . esc_attr__( 'Select all entries', 'formtheory' ) . '"></td><th scope="col">#</th><th scope="col">' . esc_html__( 'Date', 'formtheory' ) . '</th>';
		foreach ( $cols as $c ) {
			echo '<th scope="col">' . esc_html( $c['label'] ) . '</th>';
		}
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'formtheory' ) . '</span></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="' . ( count( $cols ) + 4 ) . '">' . esc_html__( 'No entries.', 'formtheory' ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			$view   = $this->admin_url( 'formtheory-entries', array( 'form' => $form_id, 'entry' => (int) $r['id'] ) );
			$unread = isset( $r['status'] ) && 'unread' === $r['status'];
			echo '<tr' . ( $unread ? ' class="ft-unread"' : '' ) . '>';
			/* translators: %d: entry number. */
			echo '<td class="check-column"><input type="checkbox" class="ft-row-check" name="ids[]" value="' . (int) $r['id'] . '" aria-label="' . esc_attr( sprintf( __( 'Select entry %d', 'formtheory' ), (int) $r['id'] ) ) . '"></td>';
			echo '<td><a href="' . esc_url( $view ) . '">' . (int) $r['id'] . '</a>' . ( $unread ? '<span class="screen-reader-text"> ' . esc_html__( '(unread)', 'formtheory' ) . '</span>' : '' ) . '</td><td>' . esc_html( $r['created_at'] ) . '</td>';
			foreach ( $cols as $c ) {
				echo '<td>' . esc_html( wp_trim_words( $this->plain_value( $r['data'][ $c['id'] ] ?? '' ), 12 ) ) . '</td>';
			}
			/* translators: %d: entry number. */
			echo '<td><a href="' . esc_url( $view ) . '">' . esc_html__( 'View', 'formtheory' ) . '<span class="screen-reader-text"> ' . esc_html( sprintf( __( 'entry %d', 'formtheory' ), (int) $r['id'] ) ) . '</span></a></td></tr>';
		}
		echo '</tbody></table></form>';

		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<nav class="ft-pager" aria-label="' . esc_attr__( 'Entries pages', 'formtheory' ) . '">';
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
			echo '<p>' . esc_html__( 'Entry not found.', 'formtheory' ) . '</p>';
			return;
		}
		if ( isset( $entry['status'] ) && 'unread' === $entry['status'] ) {
			FormTheory_DB::set_entries_status( (int) $form['id'], array( (int) $entry['id'] ), 'read' );
		}
		$back = $this->admin_url( 'formtheory-entries', array( 'form' => (int) $form['id'] ) );
		/* translators: %s: form title. */
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html( sprintf( __( 'Back to %s', 'formtheory' ), $form['title'] ) ) . '</a></p>';
		/* translators: %d: entry number. */
		echo '<h2>' . esc_html( sprintf( __( 'Entry #%d', 'formtheory' ), (int) $entry['id'] ) ) . '</h2><table class="widefat ft-entry"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Submitted', 'formtheory' ) . '</th><td>' . esc_html( $entry['created_at'] ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'IP', 'formtheory' ) . '</th><td>' . esc_html( $entry['ip'] ) . '</td></tr>';
		foreach ( $this->data_fields( $form ) as $f ) {
			$val = $entry['data'][ $f['id'] ] ?? '';
			echo '<tr><th scope="row">' . esc_html( $f['label'] ) . '</th><td>';
			if ( is_array( $val ) && isset( $val['file'] ) ) {
				$url = $this->action_url( 'formtheory_file', array( 'entry' => (int) $entry['id'], 'field' => $f['id'] ) );
				echo '<a href="' . esc_url( $url ) . '">' . esc_html( $val['name'] ) . '</a>';
			} elseif ( is_array( $val ) && isset( $val['sig'] ) ) {
				$url = $this->action_url( 'formtheory_file', array( 'entry' => (int) $entry['id'], 'field' => $f['id'] ) );
				/* translators: %s: field label. */
				$alt = sprintf( __( 'Signature drawn for %s', 'formtheory' ), $f['label'] );
				echo '<img class="ft-sig-img" src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" style="max-width:100%;height:auto;background:#fff;border:1px solid #8c8f94">';
			} else {
				echo nl2br( esc_html( $this->plain_value( $val ) ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><a class="button ft-danger" href="' . esc_url( $this->action_url( 'formtheory_delete_entry', array( 'id' => (int) $entry['id'] ) ) ) . '" data-ft-confirm="' . esc_attr__( 'Delete this entry?', 'formtheory' ) . '">' . esc_html__( 'Delete entry', 'formtheory' ) . '</a></p>';
		echo '<h3>' . esc_html__( 'Notes', 'formtheory' ) . '</h3><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ft-notes">';
		wp_nonce_field( 'formtheory_note' );
		echo '<input type="hidden" name="action" value="formtheory_note"><input type="hidden" name="entry" value="' . (int) $entry['id'] . '">';
		echo '<p><label for="ft-notes" class="screen-reader-text">' . esc_html__( 'Internal notes', 'formtheory' ) . '</label><textarea id="ft-notes" name="notes" rows="5" class="large-text">' . esc_textarea( isset( $entry['notes'] ) ? (string) $entry['notes'] : '' ) . '</textarea></p>';
		submit_button( __( 'Save note', 'formtheory' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	public function register_settings() {
		register_setting( 'formtheory_settings', 'formtheory_settings', array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
		) );
	}

	/** Merges onto the stored option so keys owned by other features survive. */
	public static function sanitize_settings( $in ) {
		$in                         = is_array( $in ) ? $in : array();
		$out                        = get_option( 'formtheory_settings', array() );
		$out                        = is_array( $out ) ? $out : array();
		$out['turnstile_site']      = sanitize_text_field( $in['turnstile_site'] ?? '' );
		$out['turnstile_secret']    = sanitize_text_field( $in['turnstile_secret'] ?? '' );
		$out['delete_on_uninstall'] = ! empty( $in['delete_on_uninstall'] );
		if ( isset( $in['retention_days'] ) ) {
			$out['retention_days'] = max( 0, min( (int) $in['retention_days'], 3650 ) );
		} elseif ( ! isset( $out['retention_days'] ) ) {
			$out['retention_days'] = 0;
		}
		return $out;
	}

	public function page_webhooks() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formtheory' ), 403 );
		}
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$status = in_array( $status, array( 'pending', 'ok', 'failed' ), true ) ? $status : '';
		$per    = 50;
		$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$total  = FormTheory_DB::count_webhook_log( $status );
		$rows   = FormTheory_DB::list_webhook_log( $per, ( $paged - 1 ) * $per, $status );
		$tabs   = array(
			''        => __( 'All', 'formtheory' ),
			'pending' => __( 'Pending', 'formtheory' ),
			'ok'      => __( 'OK', 'formtheory' ),
			'failed'  => __( 'Failed', 'formtheory' ),
		);

		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Webhook Log', 'formtheory' ) . '</h1>';
		$this->notice();
		echo '<ul class="subsubsub ft-status">';
		$i = 0;
		foreach ( $tabs as $key => $label ) {
			printf(
				'<li><a href="%s"%s>%s</a>%s</li>',
				esc_url( $this->admin_url( 'formtheory-webhooks', '' === $key ? array() : array( 'status' => $key ) ) ),
				$status === $key ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				++$i < count( $tabs ) ? ' |' : ''
			);
		}
		echo '</ul>';

		echo '<table class="widefat striped ft-table"><caption class="screen-reader-text">' . esc_html__( 'Webhook deliveries', 'formtheory' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Time', 'formtheory' ), __( 'Form', 'formtheory' ), __( 'URL', 'formtheory' ), __( 'Status', 'formtheory' ), __( 'Code', 'formtheory' ), __( 'Attempts', 'formtheory' ) ) as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'formtheory' ) . '</span></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No webhook deliveries.', 'formtheory' ) . '</td></tr>';
		}
		$titles = array();
		foreach ( $rows as $r ) {
			$fid = (int) $r['form_id'];
			if ( ! isset( $titles[ $fid ] ) ) {
				$f              = FormTheory_DB::get_form( $fid );
				$titles[ $fid ] = $f ? (string) $f['title'] : '#' . $fid;
			}
			$st = (string) $r['status'];
			echo '<tr><td>' . esc_html( $r['updated_at'] ) . '</td><td>' . esc_html( $titles[ $fid ] ) . '</td>';
			echo '<td>' . esc_html( FormTheory_Webhooks::display_url( $r['url'] ) ) . '</td>';
			echo '<td>' . esc_html( $st );
			if ( '' !== (string) $r['error'] ) {
				echo '<span class="screen-reader-text"> ' . esc_html( $r['error'] ) . '</span>';
			}
			echo '</td><td>' . ( (int) $r['http_code'] ? (int) $r['http_code'] : '&ndash;' ) . '</td><td>' . (int) $r['attempts'] . '</td><td>';
			if ( 'failed' === $st ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'formtheory_webhook_retry' );
				echo '<input type="hidden" name="action" value="formtheory_webhook_retry"><input type="hidden" name="log" value="' . (int) $r['id'] . '">';
				/* translators: %d: webhook log id. */
				echo '<button type="submit" class="button" aria-label="' . esc_attr( sprintf( __( 'Retry webhook %d', 'formtheory' ), (int) $r['id'] ) ) . '">' . esc_html__( 'Retry', 'formtheory' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<nav class="tablenav" aria-label="' . esc_attr__( 'Pagination', 'formtheory' ) . '"><div class="tablenav-pages">';
			echo wp_kses_post( paginate_links( array(
				'base'    => add_query_arg( 'paged', '%#%' ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			) ) );
			echo '</div></nav>';
		}
		echo '</div>';
	}

	public function page_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s = FormTheory_Spam::settings();
		echo '<div class="wrap"><h1>' . esc_html__( 'FormTheory Settings', 'formtheory' ) . '</h1><form method="post" action="options.php">';
		settings_fields( 'formtheory_settings' );
		FormTheory_Recaptcha::fields();
		echo '<h2>' . esc_html__( 'Cloudflare Turnstile', 'formtheory' ) . '</h2><p>' . esc_html__( 'Leave both blank to disable. A signed time-trap and honeypot are always on.', 'formtheory' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="ft-ts-site">' . esc_html__( 'Site key', 'formtheory' ) . '</label></th><td><input type="text" class="regular-text" id="ft-ts-site" name="formtheory_settings[turnstile_site]" value="' . esc_attr( $s['turnstile_site'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="ft-ts-secret">' . esc_html__( 'Secret key', 'formtheory' ) . '</label></th><td><input type="password" class="regular-text" id="ft-ts-secret" name="formtheory_settings[turnstile_secret]" value="' . esc_attr( $s['turnstile_secret'] ) . '" autocomplete="off"></td></tr>';
		echo '</table>';
		echo '<h2>' . esc_html__( 'Privacy', 'formtheory' ) . '</h2><table class="form-table" role="presentation"><tr><th scope="row"><label for="ft-ret">' . esc_html__( 'Delete entries after (days)', 'formtheory' ) . '</label></th><td><input type="number" min="0" max="3650" class="small-text" id="ft-ret" name="formtheory_settings[retention_days]" value="' . esc_attr( (string) FormTheory_Privacy::retention_days() ) . '"><p class="description">' . esc_html__( '0 keeps entries forever. Older entries and their uploaded files are deleted daily.', 'formtheory' ) . '</p></td></tr></table>';
		echo '<h2>' . esc_html__( 'Uninstall', 'formtheory' ) . '</h2><table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'Data removal', 'formtheory' ) . '</th><td><label for="ft-del"><input type="checkbox" id="ft-del" name="formtheory_settings[delete_on_uninstall]" value="1"' . checked( ! empty( $s['delete_on_uninstall'] ), true, false ) . '> ' . esc_html__( 'Delete all FormTheory data when the plugin is deleted', 'formtheory' ) . '</label><p class="description">' . esc_html__( 'Warning: all forms, entries and uploaded files are permanently deleted when the plugin is deleted. This cannot be undone.', 'formtheory' ) . '</p></td></tr></table>';
		submit_button();
		echo '</form></div>';
	}

	private function data_fields( $form ) {
		return FormTheory_Fields::data_fields( $form['config']['fields'] );
	}

	public function plain_value( $val ) {
		return FormTheory_Fields::value_to_text( $val );
	}

	private function notice() {
		$msg = isset( $_GET['ft_msg'] ) ? sanitize_key( $_GET['ft_msg'] ) : '';
		$map = array(
			'saved'   => __( 'Form saved.', 'formtheory' ),
			'deleted' => __( 'Deleted.', 'formtheory' ),
			'bulk'    => __( 'Bulk action applied.', 'formtheory' ),
			'note'    => __( 'Note saved.', 'formtheory' ),
			'imported'   => __( 'Form imported.', 'formtheory' ),
			'duplicated' => __( 'Form duplicated.', 'formtheory' ),
			'webhook_retry' => __( 'Webhook retry scheduled.', 'formtheory' ),
		);
		if ( 'import_error' === $msg ) {
			$err    = isset( $_GET['ft_err'] ) ? sanitize_key( $_GET['ft_err'] ) : '';
			$errors = array(
				'ft_import_size'   => __( 'Import file is larger than 1 MB.', 'formtheory' ),
				'ft_import_json'   => __( 'Import file is not valid JSON.', 'formtheory' ),
				'ft_import_marker' => __( 'This is not a FormTheory export file.', 'formtheory' ),
				'ft_import_empty'  => __( 'No import data was provided.', 'formtheory' ),
				'ft_import_upload' => __( 'The file could not be uploaded.', 'formtheory' ),
			);
			$text   = $errors[ $err ] ?? __( 'Import failed.', 'formtheory' );
			echo '<div class="notice notice-error is-dismissible" role="alert"><p>' . esc_html( $text ) . '</p></div>';
			return;
		}
		if ( 'webhook_retry_failed' === $msg ) {
			echo '<div class="notice notice-warning is-dismissible" role="status"><p>' . esc_html__( 'Webhook could not be rescheduled.', 'formtheory' ) . '</p></div>';
			return;
		}
		if ( 'bulk_none' === $msg ) {
			echo '<div class="notice notice-warning is-dismissible" role="status"><p>' . esc_html__( 'No entries were changed.', 'formtheory' ) . '</p></div>';
			return;
		}
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

	public function handle_webhook_retry() {
		$this->guard( 'formtheory_webhook_retry' );
		$id = isset( $_POST['log'] ) ? absint( $_POST['log'] ) : 0;
		$ok = FormTheory_Webhooks::retry( $id );
		wp_safe_redirect( $this->admin_url( 'formtheory-webhooks', array( 'ft_msg' => $ok ? 'webhook_retry' : 'webhook_retry_failed' ) ) );
		exit;
	}

	public function handle_delete_form() {
		$this->guard( 'formtheory_delete_form' );
		FormTheory_DB::delete_form( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		wp_safe_redirect( $this->admin_url( 'formtheory', array( 'ft_msg' => 'deleted' ) ) );
		exit;
	}

	public function handle_duplicate() {
		$this->guard( 'formtheory_duplicate' );
		$new = FormTheory_DB::duplicate_form( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		if ( ! $new ) {
			wp_die( esc_html__( 'Form not found.', 'formtheory' ) );
		}
		wp_safe_redirect( $this->admin_url( 'formtheory', array( 'ft_msg' => 'duplicated' ) ) );
		exit;
	}

	public function handle_export_form() {
		$this->guard( 'formtheory_export_form' );
		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$form = FormTheory_DB::get_form( $id );
		if ( ! $form ) {
			wp_die( esc_html__( 'Form not found.', 'formtheory' ) );
		}
		$json = FormTheory_Portable::export( $form );
		if ( false === $json || '' === $json ) {
			wp_die( esc_html__( 'Export failed.', 'formtheory' ), '', array( 'response' => 500 ) );
		}
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="formtheory-form-' . $id . '-' . gmdate( 'Ymd' ) . '.json"' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON download.
		exit;
	}

	public function handle_import() {
		$this->guard( 'formtheory_import' );
		$json = '';
		$err  = '';
		$file = isset( $_FILES['ft_import'] ) && is_array( $_FILES['ft_import'] ) ? $_FILES['ft_import'] : null;
		if ( $file && isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $file['error'] ) {
			$tmp = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';
			if ( UPLOAD_ERR_OK !== (int) $file['error'] || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
				$err = 'ft_import_upload';
			} elseif ( (int) ( $file['size'] ?? 0 ) > FormTheory_Portable::MAX_BYTES || filesize( $tmp ) > FormTheory_Portable::MAX_BYTES ) {
				$err = 'ft_import_size';
			} else {
				$json = (string) file_get_contents( $tmp );
			}
		} elseif ( isset( $_POST['ft_import_json'] ) ) {
			$json = (string) wp_unslash( $_POST['ft_import_json'] ); // phpcs:ignore -- validated and sanitized in FormTheory_Portable::import.
		}
		if ( '' === $err && '' === trim( $json ) ) {
			$err = 'ft_import_empty';
		}
		if ( '' === $err ) {
			$res = FormTheory_Portable::import( $json );
			if ( is_wp_error( $res ) ) {
				$err = $res->get_error_code();
			} else {
				$id = FormTheory_DB::save_form( 0, $res['title'], $res['config'] );
				wp_safe_redirect( $this->admin_url( 'formtheory-edit', array( 'id' => $id, 'ft_msg' => 'imported' ) ) );
				exit;
			}
		}
		wp_safe_redirect( $this->admin_url( 'formtheory', array( 'ft_msg' => 'import_error', 'ft_err' => $err ) ) );
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

	/**
	 * Apply a bulk action. Exit-free. Unknown action or no valid ids => 0.
	 *
	 * @param int    $form_id Form id (all writes are scoped to it).
	 * @param string $action  delete|read|unread.
	 * @param array  $ids     Entry ids; non-numeric ignored.
	 * @return int Rows affected.
	 */
	public function bulk_apply( $form_id, $action, array $ids ) {
		$form_id = (int) $form_id;
		$clean   = array();
		foreach ( $ids as $id ) {
			if ( is_scalar( $id ) && ctype_digit( (string) $id ) && (int) $id > 0 ) {
				$clean[] = (int) $id;
			}
		}
		if ( ! $clean || ! $form_id ) {
			return 0;
		}
		switch ( $action ) {
			case 'delete':
				return (int) FormTheory_DB::delete_entries( $form_id, $clean );
			case 'read':
			case 'unread':
				return (int) FormTheory_DB::set_entries_status( $form_id, $clean, $action );
		}
		return 0;
	}

	public function handle_bulk() {
		$this->guard( 'formtheory_bulk' );
		$form_id = isset( $_POST['form'] ) ? absint( $_POST['form'] ) : 0;
		$action  = isset( $_POST['ft_bulk'] ) ? sanitize_key( wp_unslash( $_POST['ft_bulk'] ) ) : '';
		$ids     = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array(); // phpcs:ignore -- cleaned in bulk_apply().
		if ( ! $form_id || ! FormTheory_DB::get_form( $form_id ) ) {
			wp_die( esc_html__( 'Form not found.', 'formtheory' ), 404 );
		}
		$changed = $this->bulk_apply( $form_id, $action, $ids );
		wp_safe_redirect( $this->admin_url( 'formtheory-entries', array( 'form' => $form_id, 'ft_msg' => self::bulk_message_key( $changed ) ) ) );
		exit;
	}

	/** Notice key for a bulk result. */
	public static function bulk_message_key( $changed ) {
		return (int) $changed > 0 ? 'bulk' : 'bulk_none';
	}

	public function handle_note() {
		$this->guard( 'formtheory_note' );
		$id    = isset( $_POST['entry'] ) ? absint( $_POST['entry'] ) : 0;
		$entry = $id ? FormTheory_DB::get_entry( $id ) : null;
		if ( ! $entry ) {
			wp_die( esc_html__( 'Entry not found.', 'formtheory' ), 404 );
		}
		$notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		FormTheory_DB::update_entry_notes( (int) $entry['id'], $notes );
		wp_safe_redirect( $this->admin_url( 'formtheory-entries', array( 'form' => (int) $entry['form_id'], 'entry' => (int) $entry['id'], 'ft_msg' => 'note' ) ) );
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
			wp_die( esc_html__( 'Form not found.', 'formtheory' ) );
		}
		$fields = $this->data_fields( $form );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="formtheory-' . $form_id . '-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		// Intentionally untranslated: keeps imports keyed on column names stable across locales.
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

	/**
	 * Download headers for a stored value. image/png inline only for a drawn
	 * signature ('sig') whose bytes start with the PNG magic; everything else
	 * is an octet-stream attachment. Always nosniff.
	 *
	 * @param array  $val  Stored entry value.
	 * @param string $head First 8 bytes of the stored file.
	 * @return string[]
	 */
	public static function file_headers( array $val, $head ) {
		$is_png = isset( $val['sig'] ) && ! isset( $val['file'] ) && "\x89PNG\r\n\x1a\n" === $head;
		$name   = str_replace( array( '"', '\\', "\r", "\n" ), '', (string) ( $val['name'] ?? '' ) );
		if ( '' === $name ) {
			$name = 'download';
		}
		return array(
			'Content-Type: ' . ( $is_png ? 'image/png' : 'application/octet-stream' ),
			'X-Content-Type-Options: nosniff',
			'Content-Disposition: ' . ( $is_png ? 'inline' : 'attachment' ) . '; filename="' . $name . '"',
		);
	}

	public function handle_file() {
		$this->guard( 'formtheory_file' );
		$entry = FormTheory_DB::get_entry( isset( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0 );
		$field = isset( $_GET['field'] ) ? sanitize_key( $_GET['field'] ) : '';
		$val   = $entry['data'][ $field ] ?? null;
		$stored = is_array( $val ) ? ( $val['file'] ?? ( $val['sig'] ?? '' ) ) : '';
		if ( ! is_string( $stored ) || '' === $stored ) {
			wp_die( esc_html__( 'File not found.', 'formtheory' ), 404 );
		}
		$path = FormTheory_DB::upload_dir() . '/' . basename( $stored );
		if ( ! is_file( $path ) ) {
			wp_die( esc_html__( 'File not found.', 'formtheory' ), 404 );
		}
		$fh   = fopen( $path, 'rb' );
		$head = $fh ? (string) fread( $fh, 8 ) : '';
		if ( $fh ) {
			fclose( $fh );
		}
		nocache_headers();
		foreach ( self::file_headers( $val, $head ) as $h ) {
			header( $h );
		}
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}
}
