<?php
defined( 'ABSPATH' ) || exit;

class FormTheory_Portable {

	const MAX_BYTES = 1048576;

	public static function export( array $form ) {
		$config = (array) ( $form['config'] ?? array() );
		foreach ( (array) ( $config['webhooks'] ?? array() ) as $i => $h ) {
			if ( is_array( $h ) ) {
				$config['webhooks'][ $i ]['secret'] = '';
			}
		}
		return wp_json_encode(
			array(
				'formtheory'     => 1,
				'plugin_version' => defined( 'FORMTHEORY_VERSION' ) ? FORMTHEORY_VERSION : '',
				'title'          => (string) ( $form['title'] ?? '' ),
				'config'         => $config,
			)
		);
	}

	/**
	 * @return array|WP_Error array{title:string,config:array}
	 */
	public static function import( $json ) {
		$json = (string) $json;
		if ( strlen( $json ) > self::MAX_BYTES ) {
			return new WP_Error( 'ft_import_size', __( 'Import file is larger than 1 MB.', 'formtheory' ) );
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ft_import_json', __( 'Import file is not valid JSON.', 'formtheory' ) );
		}
		if ( ! isset( $data['formtheory'] ) || 1 !== $data['formtheory'] ) {
			return new WP_Error( 'ft_import_marker', __( 'This is not a FormTheory export file.', 'formtheory' ) );
		}
		return array(
			'title'  => sanitize_text_field( is_string( $data['title'] ?? null ) ? $data['title'] : '' ),
			'config' => FormTheory_Fields::sanitize_config( $data['config'] ?? array() ),
		);
	}
}
