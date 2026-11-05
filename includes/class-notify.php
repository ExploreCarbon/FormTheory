<?php
defined( 'ABSPATH' ) || exit;

/** Sends each configured notification whose condition matches. */
class FormTheory_Notify {

	public static function send( array $form, array $data, $entry_id ) {
		$ctx = array( 'form' => $form, 'data' => $data, 'entry_id' => (int) $entry_id );
		foreach ( $form['config']['notifications'] ?? array() as $n ) {
			if ( ! FormTheory_Fields::cond_matches( $n['cond'] ?? array(), $data ) ) {
				continue;
			}
			$to = self::emails( $n['to'], $ctx );
			if ( ! $to ) {
				continue;
			}
			$subject = '' !== $n['subject'] ? $n['subject'] : 'New submission: {form_title}';
			$subject = FormTheory_Merge::replace( $subject, $ctx, 'header' );
			$body    = FormTheory_Merge::replace( '' !== $n['body'] ? $n['body'] : '{all_fields}', $ctx, 'text' );
			$body   .= "\n\nView: " . add_query_arg( array( 'page' => 'formtheory-entries', 'entry' => (int) $entry_id ), admin_url( 'admin.php' ) );

			$headers = array();
			$reply   = self::emails( $n['reply_to'] ?? '', $ctx );
			if ( $reply ) {
				$headers[] = 'Reply-To: ' . $reply[0];
			}
			$attachments = array();
			if ( ! empty( $n['attach_files'] ) ) {
				foreach ( $data as $v ) {
					if ( is_array( $v ) && ! empty( $v['file'] ) ) {
						$attachments[] = FormTheory_DB::upload_dir() . '/' . basename( $v['file'] );
					}
				}
			}
			wp_mail( $to, $subject, $body, $headers, $attachments );
		}
	}

	/** Resolve merge tags in a recipient list and keep valid addresses only. */
	private static function emails( $list, array $ctx ) {
		$out = array();
		foreach ( explode( ',', (string) $list ) as $r ) {
			$r = trim( FormTheory_Merge::replace( trim( $r ), $ctx, 'header' ) );
			if ( '' !== $r && is_email( $r ) ) {
				$out[] = $r;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
