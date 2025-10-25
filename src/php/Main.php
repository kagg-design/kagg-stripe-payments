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
	 * Live mode.
	 */
	private const string LIVE_MODE = 'live';

	/**
	 * Test mode.
	 */
	private const string TEST_MODE = 'test';

	/**
	 * Current mode.
	 *
	 * @var string
	 */
	private string $mode = self::LIVE_MODE;

	/**
	 * Stripe publishable key.
	 *
	 * @var string
	 */
	private string $publishable_key = '';

	/**
	 * Stripe secret key.
	 *
	 * @var string
	 */
	private string $secret_key = '';

	/**
	 * Request URI.
	 *
	 * @var string
	 */
	private string $request_uri = '';

	/**
	 * Initialize the plugin.
	 */
	public function init(): void {
		$this->mode            = $this->is_test_env() ? self::TEST_MODE : self::LIVE_MODE;
		$this->publishable_key = $this->get_publishable_key();
		$this->secret_key      = $this->get_secret_key();
		$this->request_uri     = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		// Shortcode.
		add_shortcode( 'kagg_stripe_button', [ $this, 'kagg_stripe_button_shortcode' ] );

		// Checkout.
		add_action( 'template_redirect', [ $this, 'handle_create_checkout' ] );

		// Show result notice.
		add_action(
			'wp_footer',
			static function () {
				// phpcs:disable WordPress.Security.NonceVerification.Recommended
				if ( ! isset( $_GET['kagg_stripe_status'] ) ) {
					return;
				}

				$status = sanitize_text_field( wp_unslash( $_GET['kagg_stripe_status'] ) );
				$msg    = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';

				$msg = match ( $status ) {
					'success' => 'Payment succeeded.',
					'canceled' => 'Payment canceled.',
					'error' => 'Payment error: ' . $msg,
					default => 'Unknown error.',
				};

				?>
				<div
						class="cs-stripe-notice"
						style="position:fixed; bottom:20px; left:20px; padding:10px 14px; background:#111; color:#fff; border-radius:6px; z-index:9999;">
					<?php echo esc_html( $msg ); ?>
				</div>
				<?php
				// phpcs:enable WordPress.Security.NonceVerification.Recommended
			}
		);
	}

	/**
	 * Render the Stripe Checkout button.
	 *
	 * @param array $atts Attributes.
	 *
	 * @return string
	 */
	public function kagg_stripe_button_shortcode( array $atts ): string {
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

		$action = $this->request_uri;
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
	}

	/**
	 * Get the Stripe publishable key.
	 *
	 * @return string
	 * @noinspection PhpUndefinedConstantInspection
	 */
	private function get_publishable_key(): string {
		$key_name = self::TEST_MODE === $this->mode ? 'KAGG_STRIPE_TEST_PUBLISHABLE_KEY' : 'KAGG_STRIPE_PUBLISHABLE_KEY';
		$key      = defined( $key_name ) ? constant( $key_name ) : '';

		/**
		 * Filter the publishable key.
		 *
		 * @param string $key Publishable key.
		 */
		return (string) apply_filters( 'kagg_stripe_publishable_key', $key );
	}

	/**
	 * Get the Stripe secret key.
	 *
	 * @return string
	 * @noinspection PhpUndefinedConstantInspection
	 */
	private function get_secret_key(): string {
		$key_name = self::TEST_MODE === $this->mode ? 'KAGG_STRIPE_TEST_SECRET_KEY' : 'KAGG_STRIPE_SECRET_KEY';
		$key      = defined( $key_name ) ? constant( $key_name ) : '';

		/**
		 * Filter the secret key.
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
	private function stripe_api_request( string $endpoint, array $body ): WP_Error|array {
		if ( empty( $this->secret_key ) ) {
			return new WP_Error(
				'no_key',
				'Stripe secret key is not defined. Set KAGG_STRIPE_SECRET_KEY in wp-config.php'
			);
		}

		$args = [
			'headers' => [
				'Authorization' => 'Bearer ' . $this->secret_key,
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

			return (
			new WP_Error(
				'stripe_api_error',
				$err,
				[
					'status' => $status,
					'body'   => $json,
				]
			)
			);
		}

		return $json;
	}

	/**
	 * Handle the creation checkout form submission.
	 *
	 * @return void
	 */
	#[NoReturn]
	public function handle_create_checkout(): void {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		if ( 'POST' !== $request_method ) {
			return;
		}

		$action = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';

		if ( 'kagg_create_checkout' !== $action ) {
			return;
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'kagg_create_checkout' ) ) {
			$this->show_msg( 'Invalid nonce.' );
		}

		$amount_cents = isset( $_POST['amount'] ) ? (int) $_POST['amount'] : 0;
		$currency     = isset( $_POST['currency'] )
			? sanitize_text_field( wp_unslash( $_POST['currency'] ) )
			: 'usd';
		$currency     = preg_replace( '/[^a-z]/', '', strtolower( $currency ) );
		$description  = isset( $_POST['description'] )
			? sanitize_text_field( wp_unslash( $_POST['description'] ) )
			: 'Custom Payment';

		if ( $amount_cents < 1 ) {
			$this->show_msg( 'Amount must be >= 1 cent.' );
		}

		$success_url = add_query_arg( [ 'kagg_stripe_status' => 'success' ], home_url( $this->request_uri ) );
		$cancel_url  = add_query_arg( [ 'kagg_stripe_status' => 'canceled' ], home_url( $this->request_uri ) );

		// phpcs:disable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned
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
		// phpcs:enable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned

		$result = $this->stripe_api_request( 'checkout/sessions', $body );

		if ( is_wp_error( $result ) ) {
			$status  = (int) ( $result->get_error_data()['status'] ?? 500 );
			$message = esc_html( $result->get_error_message() );

			$this->show_msg( "Stripe error: ($status) " . $message );
		}

		if ( empty( $result['url'] ) ) {
			$this->show_msg( 'Unexpected Stripe response' );
		}

		add_filter(
			'allowed_redirect_hosts',
			static function ( $hosts ) {
				return array_merge( $hosts, [ 'checkout.stripe.com' ] );
			}
		);

		wp_safe_redirect( $result['url'] );

		exit;
	}

	/**
	 * Show a message.
	 *
	 * @param string $msg Message.
	 *
	 * @return void
	 */
	#[NoReturn]
	private function show_msg( string $msg ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'kagg_stripe_status' => 'error',
					'msg'                => $msg,
				],
				$this->request_uri
			)
		);

		exit;
	}

	/**
	 * Detect local test environment.
	 *
	 * @return bool
	 */
	private function is_test_env(): bool {
		return (bool) preg_match( '/\.test$/', home_url() );
	}
}
