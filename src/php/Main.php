<?php
/**
 * Main class file.
 *
 * @package kagg-stripe
 */

// phpcs:disable Generic.Commenting.DocComment.MissingShort
/** @noinspection PhpUndefinedNamespaceInspection */
/** @noinspection PhpUndefinedClassInspection */
// phpcs:enable Generic.Commenting.DocComment.MissingShort

namespace KAGG\Stripe;

use JetBrains\PhpStorm\NoReturn;
use JsonException;
use WP_Error;

/**
 * Class Main.
 *
 * === Setup ===
 * 1) In wp-config.php define your Restricted Key (never expose to frontend):
 *    define( 'KAGG_STRIPE_SECRET_KEY', 'rk_live_XXXXXXXXXXXXXXXXXXXXXXXX' );
 *    // or test key:
 *    // define( 'KAGG_STRIPE_SECRET_KEY', 'rk_test_XXXXXXXXXXXXXXXXXXXXXXXX' );
 *
 * 2) Use shortcode anywhere:
 *    [kagg_stripe_button amount="500" currency="usd" description="Consulting" label="Pay $5"]
 *    - amount: integer cents (e.g., 500 = $5.00)
 *    - currency: ISO code (usd, eur, aed, etc.)
 *    - description: product/service name shown in Stripe Checkout
 *    - label: button text
 *    - custom_amount: "true" to render amount input users can edit
 */
class Main {
	/**
	 * Constructor.
	 */
	public function __construct() {
		// --- Shortcode ---
		add_shortcode( 'kagg_stripe_button', static function ( $atts ) {
			$atts = shortcode_atts(
				[
					'amount'        => '500', // cents.
					'currency'      => 'usd',
					'description'   => 'Custom Payment',
					'label'         => 'Pay Now',
					'custom_amount' => 'false',
				],
				$atts,
				'kagg_stripe_button'
			);

			$action = esc_url( admin_url( 'admin-post.php' ) );
			$nonce  = wp_create_nonce( 'kagg_create_checkout' );

			$amount_cents  = (int) $atts['amount'];
			$currency      = preg_replace( '/[^a-z]/', '', strtolower( $atts['currency'] ) );
			$description   = sanitize_text_field( $atts['description'] );
			$label         = esc_html( $atts['label'] );
			$custom_amount = filter_var( $atts['custom_amount'], FILTER_VALIDATE_BOOLEAN );

			$amount_field = $custom_amount
				? '<input type="number" min="1" step="1" name="amount" value="' . esc_attr( $amount_cents ) . '" required />'
				: '<input type="hidden" name="amount" value="' . esc_attr( $amount_cents ) . '" />';

			$html = '<form method="POST" action="' . $action . '" class="cs-stripe-form">';
			$html .= '<input type="hidden" name="action" value="kagg_create_checkout" />';
			$html .= '<input type="hidden" name="_wpnonce" value="' . $nonce . '" />';
			$html .= '<input type="hidden" name="currency" value="' . esc_attr( $currency ) . '" />';
			$html .= '<input type="hidden" name="description" value="' . esc_attr( $description ) . '" />';
			$html .= $amount_field;
			$html .= '<button type="submit">' . $label . '</button>';
			$html .= '</form>';

			return $html;
		} );

		// --- Handlers (logged-in and guests) ---
		add_action( 'admin_post_kagg_create_checkout', 'kagg_handle_create_checkout' );
		add_action( 'admin_post_nopriv_kagg_create_checkout', 'kagg_handle_create_checkout' );

		// --- Optional: show result notice ---
		add_action( 'wp_footer', static function () {
			if ( isset( $_GET['kagg_stripe_status'] ) ) {
				$status = sanitize_text_field( $_GET['kagg_stripe_status'] );
				$msg    = 'Payment error.';
				$msg    = $status === 'success' ? 'Payment succeeded.' : $msg;
				$msg    = $status === 'canceled' ? 'Payment canceled.' : $msg;

				echo '<div class="cs-stripe-notice" style="position:fixed; bottom:20px; left:20px; padding:10px 14px; background:#111; color:#fff; border-radius:6px; z-index:9999;">' . esc_html( $msg ) . '</div>';
			}
		} );
	}

	/**
	 * Get the Stripe secret key.
	 *
	 * @return string
	 * @noinspection PhpUndefinedConstantInspection
	 */
	private function kagg_stripe_get_secret_key(): string {
		$key = defined( 'KAGG_STRIPE_SECRET_KEY' ) ? KAGG_STRIPE_SECRET_KEY : '';

		/**
		 * Filter the secret key source if you prefer to inject from elsewhere.
		 *
		 * @param string $key Secret key.
		 */
		return (string) apply_filters( 'kagg_stripe_secret_key', $key );
	}

	/**
	 * Make a Stripe API request.
	 *
	 * @param string $endpoint Endpoint path.
	 * @param array  $body     Request body.
	 *
	 * @return array|WP_Error
	 * @noinspection PhpSameParameterValueInspection
	 */
	private function kagg_stripe_api_request( string $endpoint, array $body ): WP_Error|array {
		$key = $this->kagg_stripe_get_secret_key();

		if ( empty( $key ) ) {
			return new WP_Error( 'no_key', 'Stripe secret key is not defined. Set KAGG_STRIPE_SECRET_KEY in wp-config.php' );
		}

		$args = [
			'headers' => [
				'Authorization' => 'Bearer ' . $key,
			],
			'body'    => $body,
			'timeout' => 30,
		];

		$response = wp_remote_post( 'https://api.stripe.com/v1/' . ltrim( $endpoint, '/' ), $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );

		try {
			$json = json_decode( wp_remote_retrieve_body( $response ), true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			$json = [];
		}

		if ( $status < 200 || $status >= 300 || ! $json ) {
			$err = $json['error']['message'] ?? 'Stripe API error';

			return new WP_Error( 'stripe_api_error', $err, [ 'status' => $status, 'body' => $json ] );
		}

		return $json;
	}

	/**
	 * Handle the creation checkout form submission.
	 *
	 * @return void
	 */
	#[NoReturn]
	public function kagg_handle_create_checkout(): void {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'kagg_create_checkout' ) ) {
			wp_die( 'Invalid nonce', 403 );
		}

		$amount_cents = isset( $_POST['amount'] ) ? (int) $_POST['amount'] : 0;
		$currency     = isset( $_POST['currency'] ) ? preg_replace( '/[^a-z]/', '', strtolower( $_POST['currency'] ) ) : 'usd';
		$description  = isset( $_POST['description'] ) ? sanitize_text_field( $_POST['description'] ) : 'Custom Payment';

		if ( $amount_cents < 1 ) {
			wp_die( 'Amount must be >= 1 cent', 400 );
		}

		$success_url = add_query_arg( [ 'kagg_stripe_status' => 'success' ], home_url( add_query_arg( [], $_SERVER['REQUEST_URI'] ?? '' ) ) );
		$cancel_url  = add_query_arg( [ 'kagg_stripe_status' => 'canceled' ], home_url( add_query_arg( [], $_SERVER['REQUEST_URI'] ?? '' ) ) );

		$body = [
			'payment_method_types[]'                        => 'card',
			'mode'                                          => 'payment',
			'line_items[0][price_data][currency]'           => $currency,
			'line_items[0][price_data][unit_amount]'        => $amount_cents,
			'line_items[0][price_data][product_data][name]' => $description,
			'line_items[0][quantity]'                       => 1,
			'success_url'                                   => $success_url,
			'cancel_url'                                    => $cancel_url,
		];

		$result = $this->kagg_stripe_api_request( 'checkout/sessions', $body );

		if ( is_wp_error( $result ) ) {
			$status  = (int) ( $result->get_error_data()['status'] ?? 500 );
			$message = esc_html( $result->get_error_message() );
			wp_die( 'Stripe error: ' . $message, $status );
		}

		if ( ! empty( $result['url'] ) ) {
			wp_redirect( $result['url'] );
			exit;
		}

		wp_die( 'Unexpected Stripe response', 502 );
	}
}
