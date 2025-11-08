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
 *    - mode: "payment" or "subscription".
 *    - price: Stripe price ID (e.g., price_1234567890). Required if mode="subscription".
 *    - amount: integer cents (e.g., 500 = $5.00).
 *    - currency: ISO code (usd, eur, aed, etc.).
 *    - description: product/service name shown in Stripe Checkout.
 *    - label: button text.
 *    - custom_amount: "true" to render the amount of input users can edit.
 */
class Main {
	/**
	 * Live mode.
	 */
	private const LIVE_MODE = 'live';

	/**
	 * Test mode.
	 */
	private const TEST_MODE = 'test';

	/**
	 * Payment mode 'subscription'.
	 */
	private const MODE_SUBSCRIPTION = 'subscription';

	/**
	 * Payment mode 'payment'.
	 */
	private const MODE_PAYMENT = 'payment';

	/**
	 * Transient expiration time in seconds (24 hours).
	 */
	private const EXPIRATION = 24 * 60 * 60;

	/**
	 * Current operating mode.
	 *
	 * @var string
	 */
	private string $operating_mode = self::LIVE_MODE;

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
		$this->operating_mode  = $this->is_test_env() ? self::TEST_MODE : self::LIVE_MODE;
		$this->publishable_key = $this->get_publishable_key();
		$this->secret_key      = $this->get_secret_key();
		$this->request_uri     = Request::filter_input( INPUT_SERVER, 'REQUEST_URI' );

		// Shortcode.
		add_shortcode( 'kagg_stripe_button', [ $this, 'kagg_stripe_button_shortcode' ] );

		// Checkout.
		add_action( 'template_redirect', [ $this, 'handle_create_checkout' ] );

		// Show result notice.
		add_action( 'wp_footer', [ $this, 'show_result' ] );
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
				'mode'          => self::MODE_PAYMENT,
				'price'         => '',
				'amount'        => '0', // In cents.
				'currency'      => 'usd',
				'description'   => 'Custom Payment',
				'label'         => 'Pay Now',
				'custom_amount' => 'false',
			],
			$atts,
			'kagg_stripe_button'
		);

		$amount_cents = (int) $atts['amount'];
		$currency     = preg_replace( '/[^a-z]/', '', strtolower( $atts['currency'] ) );
		$nonce        = wp_create_nonce( 'kagg_create_checkout' );

		$html = '<form method="POST" action="' . add_query_arg( [], $this->request_uri ) . '" class="cs-stripe-form">';

		$html .= '<input type="hidden" name="action" value="kagg_create_checkout" />';
		$html .= '<input type="hidden" name="mode" value="' . esc_attr( $atts['mode'] ) . '" />';
		$html .= '<input type="hidden" name="price" value="' . esc_attr( $atts['price'] ) . '" />';
		$html .= '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '" />';
		$html .= '<input type="hidden" name="currency" value="' . esc_attr( $currency ) . '" />';
		$html .= '<input type="hidden" name="description" value="' . esc_attr( $atts['description'] ) . '" />';
		$html .= filter_var( $atts['custom_amount'], FILTER_VALIDATE_BOOLEAN )
			? '<input type="number" min="1" step="1" name="amount" value="' . esc_attr( $amount_cents ) . '" required />'
			: '<input type="hidden" name="amount" value="' . esc_attr( $amount_cents ) . '" />';
		$html .= '<button type="submit">' . esc_html( $atts['label'] ) . '</button>';
		$html .= '</form>';

		return $html;
	}

	/**
	 * Show the result notice.
	 *
	 * @return void
	 */
	public function show_result(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! Request::filter_input( INPUT_GET, 'kagg_stripe_status' ) ) {
			return;
		}

		$status     = Request::filter_input( INPUT_GET, 'kagg_stripe_status' );
		$msg        = rawurldecode( Request::filter_input( INPUT_GET, 'msg' ) );
		$session_id = Request::filter_input( INPUT_GET, 'session_id' );
		$transient  = get_transient( 'kagg_stripe_payment_id_' . $session_id );
		$id         = $transient['session']['id'] ?? '';

		$msg = match ( $status ) {
			'success' => 'Payment succeeded.',
			'canceled' => 'Payment canceled.',
			'error' => $msg,
			default => 'Unknown error.',
		};

		if ( 'error' !== $status && ( ! $session_id || $id !== $session_id ) ) {
			$status = 'error';
			$msg    = 'Wrong payment id.';
		}

		do_action( 'kagg_stripe_result', $transient, $status, $msg );

		?>
		<div
				class="cs-stripe-notice"
				style="position:fixed; bottom:20px; left:20px; padding:10px 14px; background:#111; color:#fff; border-radius:6px; z-index:9999;">
			<?php echo esc_html( $msg ); ?>
		</div>
		<?php
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Get the Stripe publishable key.
	 *
	 * @return string
	 * @noinspection PhpUndefinedConstantInspection
	 */
	private function get_publishable_key(): string {
		$key_name = self::TEST_MODE === $this->operating_mode ? 'KAGG_STRIPE_TEST_PUBLISHABLE_KEY' : 'KAGG_STRIPE_PUBLISHABLE_KEY';
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
		$key_name = self::TEST_MODE === $this->operating_mode ? 'KAGG_STRIPE_TEST_SECRET_KEY' : 'KAGG_STRIPE_SECRET_KEY';
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
		if ( ! $this->check_request() ) {
			return;
		}

		$nonce = Request::filter_input( INPUT_POST, '_wpnonce' );

		if ( ! wp_verify_nonce( $nonce, 'kagg_create_checkout' ) ) {
			$this->show_msg( 'Invalid nonce.' );
		}

		$data        = $this->get_input_data();
		$success_url = add_query_arg(
			[
				'kagg_stripe_status' => 'success',
				'session_id'         => '{CHECKOUT_SESSION_ID}',
			],
			home_url( $this->request_uri )
		);
		$cancel_url  = add_query_arg(
			[
				'kagg_stripe_status' => 'canceled ',
				'session_id'         => '{CHECKOUT_SESSION_ID}',
			],
			home_url( $this->request_uri )
		);

		// phpcs:disable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned
		$body = [
			'payment_method_types[]'  => 'card',
			'mode'                    => $data['mode'],
			'line_items[0][quantity]' => 1,
			'success_url'             => $success_url,
			'cancel_url'              => $cancel_url,
			// phpcs:enable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned
		];

		if ( self::MODE_PAYMENT === $data['mode'] ) {
			// phpcs:disable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned
			$body_price = [
				'line_items[0][price_data][currency]'           => $data['currency'],
				'line_items[0][price_data][unit_amount]'        => $data['amount_cents'],
				'line_items[0][price_data][product_data][name]' => $data['description'],
			];
			// phpcs:enable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned
		} else {
			$body_price = [
				'line_items[0][price]' => $data['price_id'],
			];
		}

		$body = array_merge( $body, $body_price );

		$user_email = (string) wp_get_current_user()?->user_email;

		if ( $user_email ) {
			$body['customer_email'] = $user_email;
		}

		$metadata = [
			'wp_user_id' => get_current_user_id(),
		];

		/**
		 * Filter the checkout metadata.
		 */
		$metadata = (array) apply_filters( 'kagg_stripe_checkout_metadata', $metadata );

		foreach ( $metadata as $key => $value ) {
			$body[ 'metadata[' . $key . ']' ] = $value;
		}

		/**
		 * Filter the checkout body.
		 *
		 * @param array $body Checkout body.
		 */
		$body = (array) apply_filters( 'kagg_stripe_checkout_body', $body );

		$session = $this->stripe_api_request( 'checkout/sessions', $body );

		$this->process_checkout( $session, $body );
	}

	/**
	 * Whether the request is a checkout request.
	 *
	 * @return bool
	 */
	private function check_request(): bool {
		$request_method = strtolower( Request::filter_input( INPUT_SERVER, 'REQUEST_METHOD' ) );
		$action         = Request::filter_input( INPUT_POST, 'action' );

		return ( 'post' === $request_method && 'kagg_create_checkout' === $action );
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
					'msg'                => rawurlencode( $msg ),
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

	/**
	 * Process the checkout.
	 *
	 * @param WP_Error|array $session Session data.
	 * @param array          $body    Body data.
	 *
	 * @return void
	 */
	#[NoReturn]
	private function process_checkout( WP_Error|array $session, array $body ): void {
		if ( is_wp_error( $session ) ) {
			$status  = (int) ( $session->get_error_data()['status'] ?? 500 );
			$message = esc_html( $session->get_error_message() );

			$this->show_msg( "Stripe error: ($status) " . $message );
		}

		if ( empty( $session['url'] ) ) {
			$this->show_msg( 'Unexpected Stripe response' );
		}

		$transient = [
			'body'    => $body,
			'session' => $session,
		];

		set_transient( 'kagg_stripe_payment_id_' . $session['id'], $transient, self::EXPIRATION );

		add_filter(
			'allowed_redirect_hosts',
			static function ( $hosts ) {
				return array_merge( $hosts, [ 'checkout.stripe.com' ] );
			}
		);

		wp_safe_redirect( $session['url'] );

		exit;
	}

	/**
	 * Get input data.
	 *
	 * @return array
	 */
	private function get_input_data(): array {
		$mode         = Request::filter_input( INPUT_POST, 'mode' );
		$price        = Request::filter_input( INPUT_POST, 'price' );
		$amount_cents = (int) Request::filter_input( INPUT_POST, 'amount' );
		$currency     = Request::filter_input( INPUT_POST, 'currency' ) ?: 'usd';
		$currency     = preg_replace( '/[^a-z]/', '', strtolower( $currency ) );
		$description  = Request::filter_input( INPUT_POST, 'description' ) ?: 'Custom Payment';

		if ( ! in_array( $mode, [ self::MODE_PAYMENT, self::MODE_SUBSCRIPTION ], true ) ) {
			$this->show_msg( 'Payment mode must be "payment" or "subscription".' );
		}

		if ( self::MODE_PAYMENT === $mode && $amount_cents < 1 ) {
			$this->show_msg( 'Amount must be >= 1 cent.' );
		}

		return compact( 'mode', 'price', 'amount_cents', 'currency', 'description' );
	}
}
