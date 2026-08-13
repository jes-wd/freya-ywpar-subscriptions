<?php
/**
 * Plugin Name: Freya YITH Points — Subscription Renewals
 * Description: Load YITH points onto the next WooCommerce Subscription renewal and keep referral purchase meta on first orders only.
 * Version:     1.1.2
 * Author:      Freya
 * Requires Plugins: woocommerce, woocommerce-subscriptions, yith-woocommerce-points-and-rewards-premium
 */

defined( 'ABSPATH' ) || exit;

define( 'FREYA_YWPAR_SUB_VERSION', '1.1.2' );
define( 'FREYA_YWPAR_SUB_FILE', __FILE__ );
define( 'FREYA_YWPAR_SUB_PATH', plugin_dir_path( __FILE__ ) );
define( 'FREYA_YWPAR_SUB_URL', plugin_dir_url( __FILE__ ) );

/**
 * Bootstrap once WooCommerce, Subscriptions, and YITH Points are all available.
 */
function freya_ywpar_subscriptions_init() {
	if ( defined( 'FREYA_YWPAR_SUB_BOOTSTRAPPED' ) ) {
		return;
	}

	if ( ! function_exists( 'WC' ) || ! function_exists( 'wcs_get_subscription' ) || ! function_exists( 'ywpar_get_customer' ) ) {
		return;
	}

	define( 'FREYA_YWPAR_SUB_BOOTSTRAPPED', true );

	require_once FREYA_YWPAR_SUB_PATH . 'includes/class-freya-ywpar-renewal-manager.php';
	require_once FREYA_YWPAR_SUB_PATH . 'includes/class-freya-ywpar-affiliate-guard.php';
	require_once FREYA_YWPAR_SUB_PATH . 'includes/class-freya-ywpar-referral-guard.php';
	require_once FREYA_YWPAR_SUB_PATH . 'includes/class-freya-ywpar-zero-order-guard.php';
	require_once FREYA_YWPAR_SUB_PATH . 'includes/class-freya-ywpar-settings-portability.php';
	require_once FREYA_YWPAR_SUB_PATH . 'includes/freya-ywpar-thankyou.php';
	require_once FREYA_YWPAR_SUB_PATH . 'includes/freya-ywpar-checkout.php';
	require_once FREYA_YWPAR_SUB_PATH . 'includes/freya-ywpar-referral.php';
	require_once FREYA_YWPAR_SUB_PATH . 'includes/class-freya-ywpar-my-account.php';
	require_once FREYA_YWPAR_SUB_PATH . 'includes/class-freya-ywpar-share-coupons.php';

	Freya_YWPAR_Renewal_Manager::instance();
	Freya_YWPAR_Affiliate_Guard::instance();
	Freya_YWPAR_Referral_Guard::instance();
	Freya_YWPAR_Zero_Order_Guard::instance();
	Freya_YWPAR_Settings_Portability::instance();
	Freya_YWPAR_My_Account::instance();
	Freya_YWPAR_Share_Coupons::instance();
}

add_action( 'plugins_loaded', 'freya_ywpar_subscriptions_init', 99 );
add_action( 'init', 'freya_ywpar_subscriptions_init', 20 );
add_action( 'woocommerce_init', 'freya_ywpar_subscriptions_init', 5 );
add_action( 'wp_loaded', 'freya_ywpar_subscriptions_init', 1 );
