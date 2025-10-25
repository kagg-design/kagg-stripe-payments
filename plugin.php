<?php
/**
 * Plugin KAGG Stripe Payments
 *
 * @package           kagg-stripe
 * @author            kaggdesign
 * @license           GPL-2.0-or-later
 * @wordpress-plugin
 *
 * Plugin Name:       KAGG Stripe Payments
 * Plugin URI:        https://kagg.eu/
 * Description:       Simple Stripe Payment plugin.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            kaggdesign
 * Author URI:        https://kagg.eu/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kagg-stripe-payments
 * Domain Path:       /languages/
 */

// phpcs:ignore Generic.Commenting.DocComment.MissingShort
/** @noinspection PhpParamsInspection */

use KAGG\Stripe\Main;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version.
 */
const KAGG_STRIPE_VERSION = '1.0.0';

/**
 * Path to the plugin dir.
 */
const KAGG_STRIPE_PATH = __DIR__;

/**
 * Plugin dir url.
 */
define( 'KAGG_STRIPE_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) );

/**
 * Main plugin file.
 */
const KAGG_STRIPE_FILE = __FILE__;

require_once KAGG_STRIPE_PATH . '/vendor/autoload.php';

/**
 * Get Main class instance.
 *
 * @return Main
 */
function kagg_stripe(): Main {
	static $kagg_stripe;

	if ( ! $kagg_stripe ) {
		$kagg_stripe = new Main();
	}

	return $kagg_stripe;
}

kagg_stripe()->init();
