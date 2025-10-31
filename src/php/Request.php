<?php
/**
 * The 'Request' class file.
 *
 * @package kagg-stripe
 */

namespace KAGG\Stripe;

/**
 * Class Request.
 */
class Request {

	/**
	 * Filter input in WP style.
	 * Nonce must be checked in the calling function.
	 *
	 * @param int    $type     Input type.
	 * @param string $var_name Variable name.
	 *
	 * @return string
	 */
	public static function filter_input( int $type, string $var_name ): string {

		return match ( $type ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			INPUT_GET => isset( $_GET[ $var_name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $var_name ] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			INPUT_POST => isset( $_POST[ $var_name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $var_name ] ) ) : '',
			INPUT_SERVER => isset( $_SERVER[ $var_name ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $var_name ] ) ) : '',
			INPUT_COOKIE => isset( $_COOKIE[ $var_name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $var_name ] ) ) : '',
			default => '',
		};
	}
}
