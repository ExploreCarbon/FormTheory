<?php
defined( 'ABSPATH' ) || exit;

class FormTheory_Frontend {

	/** form_id => array( errors, values ) for re-render after failed submit. */
	private static $state = array();

	public function __construct() {
		add_shortcode( 'formtheory', array( $this, 'shortcode' ) );
		add_action( 'init', array( $this, 'maybe_submit' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	public function register_assets() {
		wp_register_style( 'formtheory', FORMTHEORY_URL . 'assets/front.css', array(), FORMTHEORY_VERSION );
		wp_register_script( 'formtheory', FORMTHEORY_URL . 'assets/front.js', array(), FORMTHEORY_VERSION, true );
	}

	/** Server-rendered block wrapping the shortcode (no build step). */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type( 'formtheory/form', array(
			'attributes'      => array( 'id' => array( 'type' => 'number', 'default' => 0 ) ),
			'render_callback' => function ( $attrs ) {
				return $this->shortcode( array( 'id' => $attrs['id'] ?? 0 ) );
			},
		) );
	}

	/* ---------- Submit ---------- */

	public function maybe_submit() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['formtheory_id'] ) ) {
			return;
		}
		$form_id = absint( $_POST['formtheory_id'] );
		$form    = FormTheory_DB::get_form( $form_id );
		if ( ! $form ) {
			return;
		}
		$errors = array();
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ft_nonce'] ?? '' ) ), 'formtheory_submit_' . $form_id ) ) {
			$errors['_form'] = __( 'Your session expired. Please submit again.', 'formtheory' );
		}
		// Honeypot: silently pretend success.
		if ( ! empty( $_POST['ft_hp'] ) ) {
			$this->redirect_done( $form_id );
		}
		if ( ! $errors ) {
			$spam = FormTheory_Spam::check( wp_unslash( $_POST ) ); // phpcs:ignore -- only reads ft_ts / turnstile token.
			if ( is_wp_error( $spam ) ) {
				$errors['_form'] = $spam->get_error_message();
			}
		}

		$raw    = isset( $_POST['ft'] ) && is_array( $_POST['ft'] ) ? wp_unslash( $_POST['ft'] ) : array(); // phpcs:ignore -- sanitized per field.
		$fields = FormTheory_Fields::data_fields( $form['config']['fields'] );

		$values = array();
		foreach ( $fields as $f ) {
			$values[ $f['id'] ] = $this->sanitize_value( $f, $raw[ $f['id'] ] ?? '' );
		}
		$visible = FormTheory_Fields::visible_map( $fields, $values );

		$data = array();
		foreach ( $fields as $f ) {
			$id = $f['id'];
			if ( empty( $visible[ $id ] ) ) {
				continue;
			}
			if ( 'file' === $f['type'] ) {
				continue; // Handled after validation so failed submits leave no orphans.
			}
			$err = $this->validate( $f, $values[ $id ] );
			if ( $err ) {
				$errors[ $id ] = $err;
			}
			$data[ $id ] = $values[ $id ];
		}
		foreach ( $fields as $f ) {
			if ( 'file' === $f['type'] && ! empty( $visible[ $f['id'] ] ) ) {
				$err = $this->check_file( $f );
				if ( $err ) {
					$errors[ $f['id'] ] = $err;
				}
			}
		}

		if ( $errors ) {
			self::$state[ $form_id ] = array( 'errors' => $errors, 'values' => $values );
			return;
		}

		foreach ( $fields as $f ) {
			if ( 'file' === $f['type'] && ! empty( $visible[ $f['id'] ] ) ) {
				$stored = $this->store_file( $f );
				if ( is_wp_error( $stored ) ) {
					self::$state[ $form_id ] = array( 'errors' => array( $f['id'] => $stored->get_error_message() ), 'values' => $values );
					return;
				}
				if ( $stored ) {
					$data[ $f['id'] ] = $stored;
				}
			}
		}

		$entry_id = FormTheory_DB::insert_entry( $form_id, $data );
		$this->notify( $form, $fields, $data, $entry_id );
		do_action( 'formtheory_after_submission', $entry_id, $form, $data );
		$this->redirect_done( $form_id, $entry_id );
	}

	/** Redirect back with a short-lived token so the confirmation can resolve merge tags. */
	private function redirect_done( $form_id, $entry_id = 0 ) {
		$ref   = wp_get_referer();
		$url   = remove_query_arg( array( 'ft_done', 'ft_t' ), $ref ? $ref : home_url( '/' ) );
		$args  = array( 'ft_done' => $form_id );
		if ( $entry_id ) {
			$token = wp_generate_password( 20, false );
			set_transient( 'formtheory_done_' . $token, (int) $entry_id, 10 * MINUTE_IN_SECONDS );
			$args['ft_t'] = $token;
		}
		wp_safe_redirect( add_query_arg( $args, $url ) . '#formtheory-' . $form_id );
		exit;
	}

	/** Entry referenced by the confirmation token, if it belongs to this form. */
	private function done_entry( $form_id ) {
		$token = isset( $_GET['ft_t'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET['ft_t'] ) ) : '';
		if ( '' === $token ) {
			return null;
		}
		$entry = FormTheory_DB::get_entry( (int) get_transient( 'formtheory_done_' . $token ) );
		return $entry && (int) $entry['form_id'] === (int) $form_id ? $entry : null;
	}

	/** Confirmation message HTML with merge tags resolved. */
	public static function confirmation_html( array $form, array $data, $entry_id ) {
		$msg = FormTheory_Merge::replace( $form['config']['confirmation'], array( 'form' => $form, 'data' => $data, 'entry_id' => $entry_id ), 'html' );
		return wp_kses_post( wpautop( $msg ) );
	}

	private function sanitize_value( $f, $v ) {
		if ( 'checkbox' === $f['type'] ) {
			$v = is_array( $v ) ? $v : array();
			return array_values( array_intersect( array_map( 'sanitize_text_field', $v ), $f['options'] ) );
		}
		if ( is_array( $v ) ) {
			return '';
		}
		switch ( $f['type'] ) {
			case 'consent':
				return '1' === (string) $v ? __( 'Accepted', 'formtheory' ) : '';
			case 'textarea':
				return sanitize_textarea_field( $v );
			case 'email':
				return sanitize_email( $v );
			default:
				return sanitize_text_field( $v );
		}
	}

	private function validate( $f, $v ) {
		$empty = is_array( $v ) ? ! $v : '' === trim( $v );
		if ( $empty ) {
			return $f['required'] ? __( 'This field is required.', 'formtheory' ) : '';
		}
		switch ( $f['type'] ) {
			case 'email':
				return is_email( $v ) ? '' : __( 'Enter a valid email address.', 'formtheory' );
			case 'number':
				return is_numeric( $v ) ? '' : __( 'Enter a number.', 'formtheory' );
			case 'phone':
				return preg_match( '/^[0-9 +().\-]{5,25}$/', $v ) ? '' : __( 'Enter a valid phone number.', 'formtheory' );
			case 'date':
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? '' : __( 'Enter a valid date.', 'formtheory' );
			case 'select':
			case 'radio':
				return in_array( $v, $f['options'], true ) ? '' : __( 'Choose a valid option.', 'formtheory' );
		}
		return mb_strlen( $v ) > 10000 ? __( 'Too long.', 'formtheory' ) : '';
	}

	private function file_input( $f ) {
		$files = $_FILES['ft'] ?? null; // phpcs:ignore -- validated below.
		if ( ! $files || ! isset( $files['error'][ $f['id'] ] ) ) {
			return null;
		}
		return array(
			'name'     => $files['name'][ $f['id'] ],
			'type'     => $files['type'][ $f['id'] ],
			'tmp_name' => $files['tmp_name'][ $f['id'] ],
			'error'    => $files['error'][ $f['id'] ],
			'size'     => $files['size'][ $f['id'] ],
		);
	}

	private function allowed_mimes( $f ) {
		$allowed = array_filter( explode( ',', $f['allowed'] ) );
		$out     = array();
		foreach ( wp_get_mime_types() as $exts => $mime ) {
			foreach ( explode( '|', $exts ) as $e ) {
				if ( in_array( $e, $allowed, true ) && ! in_array( $e, FormTheory_Fields::BLOCKED_EXT, true ) ) {
					$out[ $e ] = $mime;
				}
			}
		}
		return $out;
	}

	private function check_file( $f ) {
		$file = $this->file_input( $f );
		if ( ! $file || UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
			return $f['required'] ? __( 'Please attach a file.', 'formtheory' ) : '';
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return __( 'Upload failed.', 'formtheory' );
		}
		if ( $file['size'] > $f['max_mb'] * MB_IN_BYTES ) {
			/* translators: %d: megabytes */
			return sprintf( __( 'File must be under %d MB.', 'formtheory' ), $f['max_mb'] );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $this->allowed_mimes( $f ) );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return __( 'File type not allowed.', 'formtheory' );
		}
		return '';
	}

	/** Moves upload into private dir under a random name. Returns null if none. */
	private function store_file( $f ) {
		$file = $this->file_input( $f );
		if ( ! $file || UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
			return null;
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $this->allowed_mimes( $f ) );
		if ( empty( $check['ext'] ) ) {
			return new WP_Error( 'ft_type', __( 'File type not allowed.', 'formtheory' ) );
		}
		FormTheory_DB::protect_upload_dir();
		$stored = wp_generate_password( 32, false ) . '.' . $check['ext'];
		$dest   = FormTheory_DB::upload_dir() . '/' . $stored;
		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			return new WP_Error( 'ft_move', __( 'Upload failed.', 'formtheory' ) );
		}
		return array(
			'file' => $stored,
			'name' => sanitize_file_name( $file['name'] ),
			'size' => (int) $file['size'],
		);
	}

	private function notify( $form, $fields, $data, $entry_id ) {
		$to = $form['config']['notify_to'];
		if ( ! $to ) {
			return;
		}
		$lines = array();
		foreach ( $fields as $f ) {
			if ( ! array_key_exists( $f['id'], $data ) ) {
				continue;
			}
			$v       = $data[ $f['id'] ];
			$v       = is_array( $v ) ? ( isset( $v['file'] ) ? $v['name'] . ' (attached in admin)' : implode( ', ', $v ) ) : $v;
			$lines[] = $f['label'] . ': ' . $v;
		}
		$lines[] = '';
		$lines[] = 'View: ' . add_query_arg( array( 'page' => 'formtheory-entries', 'entry' => $entry_id ), admin_url( 'admin.php' ) );

		$headers = array();
		foreach ( $fields as $f ) {
			if ( 'email' === $f['type'] && ! empty( $data[ $f['id'] ] ) && is_email( $data[ $f['id'] ] ) ) {
				$headers[] = 'Reply-To: ' . $data[ $f['id'] ];
				break;
			}
		}
		$subject = $form['config']['notify_subject'] ? $form['config']['notify_subject'] : 'New submission: ' . $form['title'];
		wp_mail( $to, wp_strip_all_tags( $subject ), implode( "\n", $lines ), $headers );
	}

	/* ---------- Render ---------- */

	public function shortcode( $atts ) {
		$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'formtheory' );
		$form_id = absint( $atts['id'] );
		$form    = FormTheory_DB::get_form( $form_id );
		if ( ! $form ) {
			return current_user_can( 'manage_options' ) ? '<p>' . esc_html__( 'FormTheory: form not found.', 'formtheory' ) . '</p>' : '';
		}
		wp_enqueue_style( 'formtheory' );
		wp_enqueue_script( 'formtheory' );

		$anchor = 'formtheory-' . $form_id;
		if ( isset( $_GET['ft_done'] ) && absint( $_GET['ft_done'] ) === $form_id ) {
			$entry = $this->done_entry( $form_id );
			$html  = self::confirmation_html( $form, $entry ? $entry['data'] : array(), $entry ? (int) $entry['id'] : 0 );
			return '<div id="' . esc_attr( $anchor ) . '" class="ft-confirmation" role="status" tabindex="-1">' . $html . '</div>';
		}

		$state  = self::$state[ $form_id ] ?? array( 'errors' => array(), 'values' => array() );
		$errors = $state['errors'];
		$values = $state['values'];
		$fields = $form['config']['fields'];
		$pages  = array( array() );
		foreach ( $fields as $f ) {
			if ( 'page' === $f['type'] ) {
				if ( $pages[ count( $pages ) - 1 ] ) {
					$pages[] = array( '_title' => $f['label'] );
				} else {
					$pages[ count( $pages ) - 1 ]['_title'] = $f['label'];
				}
				continue;
			}
			$pages[ count( $pages ) - 1 ][] = $f;
		}
		$multi = count( $pages ) > 1;

		ob_start();
		echo '<form id="' . esc_attr( $anchor ) . '" class="ft-form" method="post" enctype="multipart/form-data" novalidate data-ft-form>';
		echo '<input type="hidden" name="formtheory_id" value="' . (int) $form_id . '">';
		wp_nonce_field( 'formtheory_submit_' . $form_id, '_ft_nonce', false );
		echo '<input type="hidden" name="ft_ts" value="' . esc_attr( FormTheory_Spam::stamp() ) . '">';
		echo '<div class="ft-hp" aria-hidden="true"><label>Leave empty<input type="text" name="ft_hp" tabindex="-1" autocomplete="off"></label></div>';

		if ( $errors ) {
			echo '<div class="ft-error-summary" role="alert" tabindex="-1"><p>' . esc_html( $errors['_form'] ?? __( 'Please fix the errors below.', 'formtheory' ) ) . '</p></div>';
		}
		if ( $multi ) {
			echo '<p class="ft-progress" aria-live="polite" data-ft-progress></p>';
		}

		foreach ( $pages as $i => $page ) {
			$title = $page['_title'] ?? '';
			unset( $page['_title'] );
			echo '<div class="ft-page" data-ft-page="' . (int) $i . '"' . ( $multi ? ' role="group" aria-label="' . esc_attr( $title ? $title : sprintf( 'Step %d', $i + 1 ) ) . '"' : '' ) . '>';
			if ( $multi && $title ) {
				echo '<h3 class="ft-page-title">' . esc_html( $title ) . '</h3>';
			}
			foreach ( $page as $f ) {
				$this->render_field( $form_id, $f, $values[ $f['id'] ] ?? ( 'checkbox' === $f['type'] ? array() : '' ), $errors[ $f['id'] ] ?? '' );
			}
			echo '<div class="ft-nav">';
			if ( $multi && $i > 0 ) {
				echo '<button type="button" class="ft-btn ft-prev" data-ft-prev>' . esc_html__( 'Previous', 'formtheory' ) . '</button>';
			}
			if ( $multi && $i < count( $pages ) - 1 ) {
				echo '<button type="button" class="ft-btn ft-next" data-ft-next>' . esc_html__( 'Next', 'formtheory' ) . '</button>';
			} else {
				if ( FormTheory_Spam::turnstile_enabled() ) {
					wp_enqueue_script( 'formtheory-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, array( 'strategy' => 'defer' ) );
					echo '<div class="cf-turnstile ft-turnstile" data-sitekey="' . esc_attr( FormTheory_Spam::settings()['turnstile_site'] ) . '"></div>';
				}
				echo '<button type="submit" class="ft-btn ft-submit">' . esc_html( $form['config']['submit_label'] ) . '</button>';
			}
			echo '</div></div>';
		}
		echo '</form>';
		return ob_get_clean();
	}

	private function render_field( $form_id, $f, $value, $error ) {
		$id      = 'ft-' . $form_id . '-' . $f['id'];
		$name    = 'ft[' . $f['id'] . ']';
		$desc    = array();
		$req     = $f['required'] ? ' <span class="ft-req" aria-hidden="true">*</span>' : '';
		$cond    = $f['cond']['enabled'] ? ' data-ft-cond="' . esc_attr( wp_json_encode( $f['cond'] ) ) . '"' : '';
		$classes = 'ft-field ft-type-' . $f['type'] . ( $error ? ' ft-has-error' : '' );
		if ( $f['help'] ) {
			$desc[] = $id . '-help';
		}
		if ( $error ) {
			$desc[] = $id . '-err';
		}
		$aria = ( $desc ? ' aria-describedby="' . esc_attr( implode( ' ', $desc ) ) . '"' : '' )
			. ( $error ? ' aria-invalid="true"' : '' )
			. ( $f['required'] ? ' aria-required="true"' : '' );

		echo '<div class="' . esc_attr( $classes ) . '" data-ft-field="' . esc_attr( $f['id'] ) . '" data-ft-required="' . ( $f['required'] ? '1' : '0' ) . '" data-ft-type="' . esc_attr( $f['type'] ) . '"' . $cond . '>'; // phpcs:ignore -- $cond escaped above.

		if ( 'html' === $f['type'] ) {
			echo '<div class="ft-field ft-type-html"' . $cond . '>' . wp_kses_post( $f['content'] ) . '</div>'; // phpcs:ignore -- $cond escaped above.
			return;
		}
		if ( 'hidden' === $f['type'] ) {
			$v = '' !== (string) $value ? $value : FormTheory_Fields::populate( $f['default'] );
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $v ) . '">';
			return;
		}
		$group = in_array( $f['type'], array( 'radio', 'checkbox' ), true );
		if ( $group ) {
			echo '<fieldset' . $aria . '><legend>' . esc_html( $f['label'] ) . $req . '</legend>'; // phpcs:ignore -- $aria/$req static/escaped.
			foreach ( $f['options'] as $n => $opt ) {
				$oid     = $id . '-' . $n;
				$checked = 'checkbox' === $f['type'] ? in_array( $opt, (array) $value, true ) : $opt === $value;
				printf(
					'<div class="ft-choice"><input type="%1$s" id="%2$s" name="%3$s" value="%4$s"%5$s><label for="%2$s">%6$s</label></div>',
					esc_attr( $f['type'] ),
					esc_attr( $oid ),
					esc_attr( 'checkbox' === $f['type'] ? $name . '[]' : $name ),
					esc_attr( $opt ),
					checked( $checked, true, false ),
					esc_html( $opt )
				);
			}
			echo '</fieldset>';
		} elseif ( 'consent' === $f['type'] ) {
			echo '<fieldset' . $aria . '><legend>' . esc_html( $f['label'] ) . $req . '</legend>'; // phpcs:ignore -- static/escaped.
			echo '<div class="ft-choice"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( '' !== (string) $value, true, false ) . '><label for="' . esc_attr( $id ) . '">' . wp_kses_post( $f['text'] ? $f['text'] : $f['label'] ) . '</label></div>';
			echo '</fieldset>';
		} else {
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $f['label'] ) . $req . '</label>'; // phpcs:ignore
			$ph = $f['placeholder'] ? ' placeholder="' . esc_attr( $f['placeholder'] ) . '"' : '';
			switch ( $f['type'] ) {
				case 'textarea':
					echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="5"' . $ph . $aria . '>' . esc_textarea( $value ) . '</textarea>'; // phpcs:ignore
					break;
				case 'select':
					echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $aria . '><option value="">' . esc_html( $f['placeholder'] ? $f['placeholder'] : __( '— Select —', 'formtheory' ) ) . '</option>'; // phpcs:ignore
					foreach ( $f['options'] as $opt ) {
						echo '<option value="' . esc_attr( $opt ) . '"' . selected( $value, $opt, false ) . '>' . esc_html( $opt ) . '</option>';
					}
					echo '</select>';
					break;
				case 'file':
					$accept = implode( ',', array_map( function ( $e ) {
						return '.' . $e;
					}, array_filter( explode( ',', $f['allowed'] ) ) ) );
					echo '<input type="file" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" accept="' . esc_attr( $accept ) . '" data-ft-maxmb="' . (int) $f['max_mb'] . '"' . $aria . '>'; // phpcs:ignore
					/* translators: 1: extensions, 2: MB */
					echo '<p class="ft-help">' . esc_html( sprintf( __( 'Allowed: %1$s. Max %2$d MB.', 'formtheory' ), str_replace( ',', ', ', $f['allowed'] ), $f['max_mb'] ) ) . '</p>';
					break;
				default:
					$map  = array( 'text' => 'text', 'email' => 'email', 'phone' => 'tel', 'number' => 'number', 'date' => 'date' );
					$auto = array( 'email' => ' autocomplete="email"', 'phone' => ' autocomplete="tel"' );
					echo '<input type="' . esc_attr( $map[ $f['type'] ] ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . ( 'number' === $f['type'] ? ' step="any"' : '' ) . ( $auto[ $f['type'] ] ?? '' ) . $ph . $aria . '>'; // phpcs:ignore
			}
		}
		if ( $f['help'] ) {
			echo '<p class="ft-help" id="' . esc_attr( $id ) . '-help">' . esc_html( $f['help'] ) . '</p>';
		}
		echo '<p class="ft-error" id="' . esc_attr( $id ) . '-err"' . ( $error ? '' : ' hidden' ) . '>' . esc_html( $error ) . '</p>';
		echo '</div>';
	}
}
