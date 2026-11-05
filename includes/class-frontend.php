<?php
defined( 'ABSPATH' ) || exit;

class FormTheory_Frontend {

	/** form_id => array( errors, values ) for re-render after failed submit. */
	private static $state = array();

	public function __construct() {
		add_shortcode( 'formtheory', array( $this, 'shortcode' ) );
		add_action( 'init', array( $this, 'maybe_submit' ) );
		add_action( 'wp_ajax_formtheory_submit', array( $this, 'ajax_submit' ) );
		add_action( 'wp_ajax_nopriv_formtheory_submit', array( $this, 'ajax_submit' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	public function register_assets() {
		wp_register_style( 'formtheory', FORMTHEORY_URL . 'assets/front.css', array(), FORMTHEORY_VERSION );
		wp_register_script( 'formtheory', FORMTHEORY_URL . 'assets/front.js', array(), FORMTHEORY_VERSION, true );
		wp_localize_script( 'formtheory', 'FormTheoryI18n', self::i18n() );
		wp_register_script( 'formtheory-signature', FORMTHEORY_URL . 'assets/signature.js', array( 'formtheory' ), FORMTHEORY_VERSION, true );
	}

	/** Strings for assets/front.js (read as FormTheoryI18n.key). */
	public static function i18n() {
		return array(
			'required'     => __( 'This field is required.', 'formtheory' ),
			'attach_file'  => __( 'Please attach a file.', 'formtheory' ),
			'complete_address' => __( 'Please complete the address.', 'formtheory' ),
			'invalid_email' => __( 'Enter a valid email address.', 'formtheory' ),
			'invalid_number' => __( 'Enter a number.', 'formtheory' ),
			'invalid_phone' => __( 'Enter a valid phone number.', 'formtheory' ),
			/* translators: %d: maximum file size in megabytes. */
			'file_too_big' => __( 'File must be under %d MB.', 'formtheory' ),
			/* translators: 1: current step number, 2: total number of steps. */
			'step_of'      => __( 'Step %1$d of %2$d', 'formtheory' ),
			'went_wrong'   => __( 'Something went wrong. Please reload the page before submitting again.', 'formtheory' ),
			'fix_errors'   => __( 'Please fix the errors below.', 'formtheory' ),
		);
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

	/** Uploaded files for the submission being processed ($_FILES shape). */
	private $files = array();

	/** Drawn signature data URLs (field id => string) for the submission being processed; kept out of $values. */
	private $sig_data = array();

	/** PNG signature: magic bytes, max decoded size, max dimensions, max base64 length checked before decoding. */
	const SIG_PREFIX    = 'data:image/png;base64,';
	const SIG_MAX_BYTES = 204800;
	const SIG_MAX_B64   = 280000;
	const SIG_MAX_W     = 2000;
	const SIG_MAX_H     = 1000;

	/** Classic (non-JS) POST handler. */
	public function maybe_submit() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['formtheory_id'] ) || wp_doing_ajax() ) {
			return;
		}
		$form_id = absint( $_POST['formtheory_id'] );
		$form    = FormTheory_DB::get_form( $form_id );
		if ( ! $form ) {
			return;
		}
		$r = $this->process( $form, wp_unslash( $_POST ), $_FILES ); // phpcs:ignore -- sanitized per field in process().
		if ( ! $r['ok'] ) {
			self::$state[ $form_id ] = array( 'errors' => $r['errors'], 'values' => $r['values'] );
			return;
		}
		if ( $r['redirect'] ) {
			// Host was fixed by the admin in the form config; allow it even if off-site.
			wp_redirect( $r['redirect'] ); // phpcs:ignore WordPress.Security.SafeRedirect -- validated in confirmation_target().
			exit;
		}
		$this->redirect_done( $form_id, (int) $r['entry_id'] );
	}

	/** AJAX handler: same pipeline, JSON response. */
	public function ajax_submit() {
		$form = FormTheory_DB::get_form( absint( $_POST['formtheory_id'] ?? 0 ) );
		if ( ! $form ) {
			wp_send_json( array( 'ok' => false, 'errors' => array( '_form' => __( 'Form not found.', 'formtheory' ) ) ), 404 );
		}
		$r = $this->process( $form, wp_unslash( $_POST ), $_FILES ); // phpcs:ignore -- sanitized per field in process().
		unset( $r['values'] );
		wp_send_json( $r );
	}

	/**
	 * Validate, store and notify. Never redirects or exits.
	 *
	 * @param array $form  Form row.
	 * @param array $post  Unslashed POST data.
	 * @param array $files $_FILES-shaped array.
	 * @return array { ok: bool, errors: array, values: array, entry_id: ?int, redirect: ?string, message: ?string }
	 */
	public function process( array $form, array $post, array $files ) {
		$form_id     = (int) $form['id'];
		$this->files    = $files;
		$this->sig_data = array();
		$result     = array( 'ok' => false, 'errors' => array(), 'values' => array(), 'entry_id' => null, 'redirect' => null, 'message' => null );

		// Honeypot: silently pretend success, store nothing.
		if ( ! empty( $post['ft_hp'] ) ) {
			return array_merge( $result, array( 'ok' => true, 'message' => self::confirmation_html( $form, array(), 0 ) ) );
		}
		$errors = array();
		if ( ! wp_verify_nonce( sanitize_text_field( (string) ( $post['_ft_nonce'] ?? '' ) ), 'formtheory_submit_' . $form_id ) ) {
			$errors['_form'] = __( 'Your session expired. Please submit again.', 'formtheory' );
		} else {
			$spam = FormTheory_Spam::check( $post );
			if ( is_wp_error( $spam ) ) {
				$errors['_form'] = $spam->get_error_message();
			} else {
				$captcha = FormTheory_Recaptcha::verify();
				if ( $captcha ) {
					$errors['_form'] = $captcha;
				}
			}
		}
		if ( ! $errors ) {
			$captcha = FormTheory_Recaptcha::verify();
			if ( $captcha ) {
				$errors['_form'] = $captcha;
			}
		}

		$raw    = isset( $post['ft'] ) && is_array( $post['ft'] ) ? $post['ft'] : array();
		$fields = FormTheory_Fields::data_fields( $form['config']['fields'] );
		$values = array();
		foreach ( $fields as $f ) {
			$values[ $f['id'] ] = $this->sanitize_value( $f, $raw[ $f['id'] ] ?? '' );
		}
		// Calc fields: posted values are ignored and recomputed. Pass 1 uses all
		// sanitized values so calc results can drive conditions; pass 2 (stored)
		// treats fields hidden by those conditions (calc included) as empty, like
		// front.js does. One-shot: if pass 2 changes a calc value that a
		// condition reads, visibility is not re-evaluated (pass 1 decides it).
		$values  = FormTheory_Calc::compute( $fields, $values );
		$visible = FormTheory_Fields::visible_map( $fields, $values );
		$shown   = array();
		foreach ( $values as $vid => $v ) {
			$shown[ $vid ] = empty( $visible[ $vid ] ) ? '' : $v;
		}
		foreach ( FormTheory_Calc::compute( $fields, $shown, $visible ) as $vid => $v ) {
			if ( ! empty( $visible[ $vid ] ) ) {
				$values[ $vid ] = $v;
			}
		}
		$result['values'] = $values;

		$data = array();
		foreach ( $fields as $f ) {
			$id = $f['id'];
			if ( empty( $visible[ $id ] ) ) {
				continue;
			}
			if ( 'file' === $f['type'] ) {
				$err = $this->check_file( $f ); // Stored after validation so failed submits leave no orphans.
			} elseif ( 'signature' === $f['type'] ) {
				$err         = $this->check_signature( $f, $values[ $id ] ); // Drawn image written after validation, like files.
				$data[ $id ] = $values[ $id ];
			} else {
				$err         = $this->validate( $f, $values[ $id ] );
				$data[ $id ] = $values[ $id ];
			}
			if ( $err ) {
				$errors[ $id ] = $err;
			}
		}
		if ( $errors ) {
			$result['errors'] = $errors;
			return $result;
		}

		foreach ( $fields as $f ) {
			if ( 'file' === $f['type'] && ! empty( $visible[ $f['id'] ] ) ) {
				$stored = $this->store_file( $f );
				if ( is_wp_error( $stored ) ) {
					$result['errors'] = array( $f['id'] => $stored->get_error_message() );
					return $result;
				}
				if ( $stored ) {
					$data[ $f['id'] ] = $stored;
				}
			}
			$sig_data = $this->sig_data[ $f['id'] ] ?? '';
			if ( 'signature' === $f['type'] && ! empty( $visible[ $f['id'] ] ) && '' !== $sig_data ) {
				$stored = $this->store_signature( $f, $sig_data ); // Drawn wins over typed.
				if ( is_wp_error( $stored ) ) {
					$result['errors'] = array( $f['id'] => $stored->get_error_message() );
					return $result;
				}
				$data[ $f['id'] ] = $stored;
			}
		}

		$entry_id = FormTheory_DB::insert_entry( $form_id, $data );
		FormTheory_Notify::send( $form, $data, $entry_id );
		do_action( 'formtheory_after_submission', $entry_id, $form, $data );

		$ctx                = array( 'form' => $form, 'data' => $data, 'entry_id' => $entry_id );
		$result['ok']       = true;
		$result['entry_id'] = $entry_id;
		$result['redirect'] = self::confirmation_target( $form, $ctx );
		$result['message']  = $result['redirect'] ? null : self::confirmation_html( $form, $data, $entry_id );
		return $result;
	}

	/** Redirect back with a short-lived token so the confirmation can resolve merge tags. */
	private function redirect_done( $form_id, $entry_id = 0 ) {
		// wp_get_referer() is false when the form posts back to its own URL (the normal case), so validate the raw referer instead.
		$url   = remove_query_arg( array( 'ft_done', 'ft_t' ), wp_validate_redirect( (string) wp_get_raw_referer(), home_url( '/' ) ) );
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
		$entry_id = (int) get_transient( 'formtheory_done_' . $token );
		delete_transient( 'formtheory_done_' . $token ); // Single use: a shared URL must not re-expose entry data.
		$entry = FormTheory_DB::get_entry( $entry_id );
		return $entry && (int) $entry['form_id'] === (int) $form_id ? $entry : null;
	}

	/**
	 * Where to send the visitor after a successful submit, or null to show
	 * the confirmation message. Redirect URLs resolve merge tags URL-encoded,
	 * and are refused if a value changed the configured host.
	 */
	public static function confirmation_target( array $form, array $ctx ) {
		$cfg = $form['config'];
		$type = $cfg['confirmation_type'] ?? 'message';
		if ( 'page' === $type ) {
			$id = (int) ( $cfg['confirmation_page'] ?? 0 );
			return $id && 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : null;
		}
		if ( 'redirect' !== $type || empty( $cfg['confirmation_url'] ) ) {
			return null;
		}
		$template = (string) $cfg['confirmation_url'];
		$url      = FormTheory_Merge::replace( $template, $ctx, 'url' );
		$expected = wp_parse_url( preg_replace( '/\{[^}]*\}/', '', $template ), PHP_URL_HOST );
		$actual   = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $expected || strtolower( (string) $expected ) !== strtolower( (string) $actual ) || ! preg_match( '#^https?://#i', $url ) ) {
			return null;
		}
		return $url;
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
		if ( 'address' === $f['type'] ) {
			// Stored shape: enabled, non-empty parts only, in canonical order; nothing filled in => '' (keeps entries clean).
			$v   = is_array( $v ) ? $v : array();
			$out = array();
			foreach ( FormTheory_Fields::address_parts( $f['parts'] ?? array() ) as $part ) {
				$p = $v[ $part ] ?? '';
				$p = is_string( $p ) ? mb_substr( sanitize_text_field( $p ), 0, 200 ) : '';
				if ( '' !== $p ) {
					$out[ $part ] = $p;
				}
			}
			return $out ? $out : '';
		}
		if ( 'signature' === $f['type'] ) {
			// Drawn data URL is kept aside (validated byte-for-byte later, never echoed back); value is the typed name or ''.
			$v     = is_array( $v ) ? $v : array();
			$data  = isset( $v['data'] ) && is_string( $v['data'] ) ? $v['data'] : '';
			$typed = isset( $v['typed'] ) && is_string( $v['typed'] ) ? mb_substr( sanitize_text_field( $v['typed'] ), 0, 120 ) : '';
			$this->sig_data[ $f['id'] ] = $data;
			return '' !== $typed ? array( 'typed' => $typed ) : '';
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
		if ( is_array( $v ) ) {
			if ( 'address' === $f['type'] ) {
				// Required: every enabled part except street2 must be filled. Optional: any subset is fine.
				if ( $f['required'] ) {
					foreach ( FormTheory_Fields::address_parts( $f['parts'] ?? array() ) as $part ) {
						if ( 'street2' !== $part && empty( $v[ $part ] ) ) {
							return __( 'Please complete the address.', 'formtheory' );
						}
					}
				}
				return '';
			}
			// Checkbox values were already intersected with the options in sanitize_value().
			if ( 'checkbox' === $f['type'] ) {
				return '';
			}
			return 'rating' === $f['type'] ? __( 'Choose a valid rating.', 'formtheory' ) : __( 'Invalid value.', 'formtheory' );
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
			case 'rating':
				$max = FormTheory_Fields::rating_max( $f['max'] ?? 5 );
				return is_string( $v ) && preg_match( '/^[1-9][0-9]?$/', trim( $v ) ) && (int) $v <= $max ? '' : __( 'Choose a valid rating.', 'formtheory' );
			case 'select':
			case 'radio':
				return in_array( $v, $f['options'], true ) ? '' : __( 'Choose a valid option.', 'formtheory' );
		}
		return is_string( $v ) && mb_strlen( $v ) > 10000 ? __( 'Too long.', 'formtheory' ) : '';
	}

	private function file_input( $f ) {
		$files = $this->files['ft'] ?? null;
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
			/* translators: %d: maximum file size in megabytes. */
			return sprintf( __( 'File must be under %d MB.', 'formtheory' ), $f['max_mb'] );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $this->allowed_mimes( $f ) );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return __( 'File type not allowed.', 'formtheory' );
		}
		return '';
	}

	/** Validation-only (no writes): drawn data must be a valid PNG; otherwise typed name or required. */
	private function check_signature( $f, $value ) {
		$data = $this->sig_data[ $f['id'] ] ?? '';
		if ( '' !== $data ) {
			$bytes = self::decode_signature( $data );
			return is_wp_error( $bytes ) ? $bytes->get_error_message() : '';
		}
		if ( is_array( $value ) && '' !== trim( (string) ( $value['typed'] ?? '' ) ) ) {
			return '';
		}
		return $f['required'] ? __( 'This field is required.', 'formtheory' ) : '';
	}

	/**
	 * Strictly decode a drawn-signature data URL. Order matters: prefix and
	 * string length are checked before decoding to bound memory.
	 *
	 * @return string|WP_Error PNG bytes.
	 */
	private static function decode_signature( $data_url ) {
		$bad = new WP_Error( 'ft_sig', __( 'The signature could not be read. Please clear it and sign again.', 'formtheory' ) );
		if ( ! is_string( $data_url ) || 0 !== strpos( $data_url, self::SIG_PREFIX ) ) {
			return $bad;
		}
		$b64 = substr( $data_url, strlen( self::SIG_PREFIX ) );
		if ( strlen( $b64 ) > self::SIG_MAX_B64 ) {
			return new WP_Error( 'ft_sig_size', __( 'The signature image is too large. Please clear it and sign again.', 'formtheory' ) );
		}
		$bytes = base64_decode( $b64, true );
		if ( false === $bytes || '' === $bytes ) {
			return $bad;
		}
		if ( strlen( $bytes ) > self::SIG_MAX_BYTES ) {
			return new WP_Error( 'ft_sig_size', __( 'The signature image is too large. Please clear it and sign again.', 'formtheory' ) );
		}
		if ( 0 !== strncmp( $bytes, "\x89PNG\r\n\x1a\n", 8 ) ) {
			return $bad;
		}
		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- malformed input is expected.
		if ( ! $info || 'image/png' !== ( $info['mime'] ?? '' ) || IMAGETYPE_PNG !== ( $info[2] ?? 0 )
			|| $info[0] < 1 || $info[1] < 1 || $info[0] > self::SIG_MAX_W || $info[1] > self::SIG_MAX_H ) {
			return $bad;
		}
		return $bytes;
	}

	/**
	 * Re-validate and write a drawn signature to the private dir under a random 32-hex name.
	 *
	 * @return array|WP_Error { sig: string, name: string }
	 */
	public function store_signature( array $f, $data_url ) {
		$bytes = self::decode_signature( $data_url );
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}
		FormTheory_DB::protect_upload_dir();
		$stored = bin2hex( random_bytes( 16 ) ) . '.png';
		if ( strlen( $bytes ) !== file_put_contents( FormTheory_DB::upload_dir() . '/' . $stored, $bytes, LOCK_EX ) ) {
			return new WP_Error( 'ft_sig_write', __( 'Upload failed.', 'formtheory' ) );
		}
		return array( 'sig' => $stored, 'name' => 'signature.png' );
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
		if ( in_array( 'signature', array_column( $form['config']['fields'], 'type' ), true ) ) {
			wp_enqueue_script( 'formtheory-signature' );
		}

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
		$values = FormTheory_Calc::compute( FormTheory_Fields::data_fields( $fields ), $values ); // Initial calc output.
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
		$ajax = ! empty( $form['config']['ajax'] ) ? ' data-ft-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"' : '';
		echo '<form id="' . esc_attr( $anchor ) . '" class="ft-form" method="post" enctype="multipart/form-data" novalidate data-ft-form' . $ajax . '>'; // phpcs:ignore -- $ajax escaped above.
		echo '<input type="hidden" name="formtheory_id" value="' . (int) $form_id . '">';
		wp_nonce_field( 'formtheory_submit_' . $form_id, '_ft_nonce', false );
		echo '<input type="hidden" name="ft_ts" value="' . esc_attr( FormTheory_Spam::stamp() ) . '">';
		echo '<div class="ft-hp" aria-hidden="true"><label>' . esc_html__( 'Leave empty', 'formtheory' ) . '<input type="text" name="ft_hp" tabindex="-1" autocomplete="off"></label></div>';

		if ( $errors ) {
			echo '<div class="ft-error-summary" role="alert" tabindex="-1"><p>' . esc_html( $errors['_form'] ?? __( 'Please fix the errors below.', 'formtheory' ) ) . '</p>';
			$items = '';
			foreach ( $fields as $sf ) {
				if ( ! empty( $errors[ $sf['id'] ] ) ) {
					$items .= '<li><a href="#' . esc_attr( 'ft-' . $form_id . '-' . $sf['id'] . '-wrap' ) . '" data-ft-goto="' . esc_attr( $sf['id'] ) . '">' . esc_html( ( '' !== (string) $sf['label'] ? $sf['label'] . ': ' : '' ) . $errors[ $sf['id'] ] ) . '</a></li>';
				}
			}
			if ( $items ) {
				echo '<ul>' . $items . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
			}
			echo '</div>';
		}
		if ( $multi ) {
			echo '<p class="ft-progress" aria-live="polite" data-ft-progress></p>';
		}

		foreach ( $pages as $i => $page ) {
			$title = $page['_title'] ?? '';
			unset( $page['_title'] );
			echo '<div class="ft-page" data-ft-page="' . (int) $i . '"' . ( $multi ? ' role="group" aria-label="' . esc_attr( $title ? $title : sprintf( /* translators: %d: step number. */ __( 'Step %d', 'formtheory' ), $i + 1 ) ) . '"' : '' ) . '>';
			if ( $multi && $title ) {
				echo '<h3 class="ft-page-title">' . esc_html( $title ) . '</h3>';
			}
			foreach ( $page as $f ) {
				$this->render_field( $form_id, $f, $values[ $f['id'] ] ?? ( 'checkbox' === $f['type'] ? array() : '' ), $errors[ $f['id'] ] ?? '' );
			}
			if ( $i === count( $pages ) - 1 ) {
				echo FormTheory_Recaptcha::render(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render().
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
					echo '<noscript><p class="ft-help">' . esc_html__( 'This form needs JavaScript enabled for its spam check.', 'formtheory' ) . '</p></noscript>';
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
		if ( 'file' === $f['type'] ) {
			$desc[] = $id . '-hint';
		}
		if ( $error ) {
			$desc[] = $id . '-err';
		}
		// aria-required is not allowed on a plain group (checkbox group / consent): those get a visually hidden "required" in the legend; consent puts all aria on its checkbox.
		$aria_base = ( $desc ? ' aria-describedby="' . esc_attr( implode( ' ', $desc ) ) . '"' : '' )
			. ( $error ? ' aria-invalid="true"' : '' );
		$aria      = $aria_base . ( $f['required'] ? ' aria-required="true"' : '' );
		$req_sr    = $f['required'] ? '<span class="ft-sr"> ' . esc_html__( '(required)', 'formtheory' ) . '</span>' : '';

		echo '<div id="' . esc_attr( $id ) . '-wrap" class="' . esc_attr( $classes ) . '" data-ft-field="' . esc_attr( $f['id'] ) . '" data-ft-required="' . ( $f['required'] ? '1' : '0' ) . '" data-ft-type="' . esc_attr( $f['type'] ) . '"' . $cond . '>'; // phpcs:ignore -- $cond escaped above.

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
			echo '<fieldset' . ( 'radio' === $f['type'] ? ' role="radiogroup"' . $aria : $aria_base ) . '><legend>' . esc_html( $f['label'] ) . $req . ( 'radio' === $f['type'] ? '' : $req_sr ) . '</legend>'; // phpcs:ignore -- $aria/$req static/escaped.
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
		} elseif ( 'rating' === $f['type'] ) {
			// Real radios (values 1..max) in the same fieldset/legend structure as `radio`; stars are CSS-only.
			echo '<fieldset class="ft-rating" role="radiogroup"' . $aria . '><legend>' . esc_html( $f['label'] ) . $req . '</legend>'; // phpcs:ignore -- $aria/$req static/escaped.
			$max = FormTheory_Fields::rating_max( $f['max'] ?? 5 );
			for ( $n = 1; $n <= $max; $n++ ) {
				$oid = $id . '-' . $n;
				/* translators: 1: chosen rating, 2: maximum rating (for example "3 of 5") */
				$txt = sprintf( __( '%1$d of %2$d', 'formtheory' ), $n, $max );
				printf(
					'<div class="ft-choice ft-star"><input type="radio" id="%1$s" name="%2$s" value="%3$d"%4$s><label for="%1$s"><span class="ft-sr">%5$s</span></label></div>',
					esc_attr( $oid ),
					esc_attr( $name ),
					$n,
					checked( (string) $n, (string) $value, false ),
					esc_html( $txt )
				);
			}
			echo '</fieldset>';
		} elseif ( 'address' === $f['type'] ) {
			// One labelled text input per part inside a fieldset; required state is on each required input (street2 never), error state on the fieldset.
			$labels = array(
				'street'  => __( 'Street address', 'formtheory' ),
				'street2' => __( 'Apartment, suite, etc.', 'formtheory' ),
				'city'    => __( 'City', 'formtheory' ),
				'state'   => __( 'State / Province', 'formtheory' ),
				'zip'     => __( 'ZIP / Postal code', 'formtheory' ),
				'country' => __( 'Country', 'formtheory' ),
			);
			$tokens = array( 'street' => 'address-line1', 'street2' => 'address-line2', 'city' => 'address-level2', 'state' => 'address-level1', 'zip' => 'postal-code', 'country' => 'country-name' );
			$vals   = is_array( $value ) ? $value : array();
			echo '<fieldset class="ft-address"' . $aria_base . '><legend>' . esc_html( $f['label'] ) . $req . $req_sr . '</legend>'; // phpcs:ignore -- $aria_base/$req static/escaped.
			foreach ( FormTheory_Fields::address_parts( $f['parts'] ?? array() ) as $part ) {
				$pid = $id . '-' . $part;
				$pv  = isset( $vals[ $part ] ) && is_string( $vals[ $part ] ) ? $vals[ $part ] : '';
				if ( 'country' === $part && '' === $pv ) {
					$pv = (string) ( $f['country_default'] ?? '' );
				}
				$preq = $f['required'] && 'street2' !== $part ? ' aria-required="true"' : '';
				echo '<div class="ft-addr-part ft-addr-' . esc_attr( $part ) . '"><label for="' . esc_attr( $pid ) . '">' . esc_html( $labels[ $part ] ) . '</label>';
				echo '<input type="text" id="' . esc_attr( $pid ) . '" name="' . esc_attr( $name . '[' . $part . ']' ) . '" value="' . esc_attr( $pv ) . '" autocomplete="' . esc_attr( $tokens[ $part ] ) . '"' . $preq . '></div>'; // phpcs:ignore -- $preq static.
			}
			echo '</fieldset>';
		} elseif ( 'signature' === $f['type'] ) {
			// Canvas is an optional pointer-only aid; the labelled typed-name input is the keyboard/accessible path (WCAG 2.5.7).
			$typed = is_array( $value ) && isset( $value['typed'] ) && is_string( $value['typed'] ) ? $value['typed'] : '';
			echo '<fieldset class="ft-signature"' . $aria_base . '><legend>' . esc_html( $f['label'] ) . $req . $req_sr . '</legend>'; // phpcs:ignore -- $aria_base/$req static/escaped.
			echo '<div class="ft-sig-pad"><canvas class="ft-sig-canvas" width="600" height="200" role="img" aria-label="' . esc_attr__( 'Optional drawing area: sign with a mouse, pen or finger, or type your name below.', 'formtheory' ) . '" data-ft-sig-canvas></canvas></div>';
			echo '<button type="button" class="ft-btn ft-sig-clear" data-ft-sig-clear>' . esc_html__( 'Clear signature', 'formtheory' ) . '</button>';
			echo '<input type="hidden" name="' . esc_attr( $name . '[data]' ) . '" value="" data-ft-sig-data>';
			echo '<div class="ft-sig-typed"><label for="' . esc_attr( $id . '-typed' ) . '">' . esc_html__( 'Or type your full name to sign', 'formtheory' ) . '</label>';
			echo '<input type="text" id="' . esc_attr( $id . '-typed' ) . '" name="' . esc_attr( $name . '[typed]' ) . '" value="' . esc_attr( $typed ) . '" maxlength="120" autocomplete="name"></div>';
			echo '</fieldset>';
		} elseif ( 'consent' === $f['type'] ) {
			echo '<fieldset><legend>' . esc_html( $f['label'] ) . $req . $req_sr . '</legend>'; // phpcs:ignore -- static/escaped.
			echo '<div class="ft-choice"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( '' !== (string) $value, true, false ) . $aria . '><label for="' . esc_attr( $id ) . '">' . wp_kses_post( $f['text'] ? $f['text'] : $f['label'] ) . '</label></div>';
			echo '</fieldset>';
		} elseif ( 'calc' === $f['type'] ) {
			// Read-only result. aria-live="off": no announcement on every keystroke; users reach the labelled value by reading/tabbing order.
			$for = implode( ' ', array_map( function ( $ref ) use ( $form_id ) {
				return 'ft-' . $form_id . '-' . $ref;
			}, FormTheory_Calc::refs( $f['formula'] ) ) );
			$shown = '' !== (string) $value ? $f['prefix'] . $value . $f['suffix'] : '';
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $f['label'] ) . '</label>';
			echo '<output id="' . esc_attr( $id ) . '" class="ft-calc"' . ( $for ? ' for="' . esc_attr( $for ) . '"' : '' ) . ' aria-live="off" data-ft-calc="' . esc_attr( $f['formula'] ) . '" data-ft-decimals="' . (int) $f['decimals'] . '" data-ft-prefix="' . esc_attr( $f['prefix'] ) . '" data-ft-suffix="' . esc_attr( $f['suffix'] ) . '" data-ft-value="' . esc_attr( $value ) . '"' . $aria_base . '>' . esc_html( $shown ) . '</output>'; // phpcs:ignore -- $aria_base static/escaped.
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
					echo '<p class="ft-help" id="' . esc_attr( $id ) . '-hint">' . esc_html( sprintf( __( 'Allowed: %1$s. Max %2$d MB.', 'formtheory' ), str_replace( ',', ', ', $f['allowed'] ), $f['max_mb'] ) ) . '</p>';
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
