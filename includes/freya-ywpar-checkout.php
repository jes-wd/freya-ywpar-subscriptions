<?php
/**
 * YITH Points & Rewards integration for Freya custom checkout.
 *
 * @package Freya_YWPAR_Subscriptions
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Whether the current request is part of the Freya checkout flow (page or checkout AJAX).
 *
 * @return bool
 */
function freya_checkout_ywpar_is_checkout_context()
{
	if (function_exists('freya_is_freya_checkout_frontend') && freya_is_freya_checkout_frontend()) {
		return true;
	}

	if (!defined('DOING_AJAX') || !DOING_AJAX) {
		return false;
	}

	$wc_ajax = isset($_GET['wc-ajax']) ? sanitize_text_field(wp_unslash($_GET['wc-ajax'])) : '';
	if (in_array($wc_ajax, array(
		'update_order_review',
		'checkout',
		'ywpar_apply_points',
		'ywpar_update_cart_rewards_messages',
		'ywpar_calc_discount_value',
	), true)) {
		return true;
	}

	$action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';
	return in_array($action, array('freya_switch_checkout_product', 'freya_ca_checkout_prepaid_switch'), true);
}

/**
 * Whether YITH redeem UI should run on the current Freya checkout request.
 *
 * @return bool
 */
function freya_checkout_ywpar_is_active()
{
	if (!function_exists('yith_points') || !function_exists('ywpar_get_option')) {
		return false;
	}

	if ('yes' !== ywpar_get_option('enable_rewards_points')) {
		return false;
	}

	if (!freya_checkout_ywpar_is_checkout_context()) {
		return false;
	}

	if (!is_user_logged_in()) {
		return false;
	}

	$customer = ywpar_get_current_customer();
	return $customer && $customer->is_enabled('redeem');
}

/**
 * Stop YITH from printing the rewards form above the checkout layout.
 *
 * @return void
 */
function freya_checkout_ywpar_detach_default_rewards_hook()
{
	if (!function_exists('yith_points')) {
		return;
	}

	$frontend = yith_points()->frontend;
	if ($frontend) {
		remove_action('woocommerce_before_checkout_form', array($frontend, 'print_rewards_message_in_cart'));
	}
}

/**
 * Print the YITH rewards redeem form beside the Freya coupon row.
 *
 * @return void
 */
function freya_checkout_ywpar_rewards_message()
{
	if (!freya_checkout_ywpar_is_active()) {
		return;
	}

	yith_points()->frontend->print_rewards_message_in_cart();
}

/**
 * Capture rewards markup for WooCommerce checkout fragment refresh.
 *
 * @return string
 */
function freya_checkout_ywpar_rewards_message_html()
{
	if (!freya_checkout_ywpar_is_active()) {
		return '<div id="yith-par-message-reward-cart" hidden></div>';
	}

	ob_start();
	yith_points()->frontend->print_rewards_message_in_cart();
	$html = ob_get_clean();

	if ($html === '') {
		return '<div id="yith-par-message-reward-cart" hidden></div>';
	}

	return $html;
}

/**
 * Use AJAX redeem + update_checkout instead of a full-page POST on custom checkout.
 *
 * @param bool $use_ajax Whether YITH should redeem via AJAX.
 * @return bool
 */
function freya_checkout_ywpar_enable_ajax_redeem($use_ajax)
{
	return freya_checkout_ywpar_is_active() ? true : $use_ajax;
}
add_filter('ywpar_redeem_uses_ajax', 'freya_checkout_ywpar_enable_ajax_redeem');

/**
 * Anchor YITH's dynamic insert/refresh to the Freya coupon form.
 *
 * @param string $selector Default YITH container selector.
 * @return string
 */
function freya_checkout_ywpar_default_container($selector)
{
	if (freya_checkout_ywpar_is_active()) {
		return '.freya-checkout-section form.checkout_coupon.woocommerce-form-coupon';
	}

	return $selector;
}
add_filter('ywpar_default_container', 'freya_checkout_ywpar_default_container');

/**
 * Keep the rewards form in sync when checkout totals refresh (coupons, plan switch, etc.).
 *
 * @param array<string, string> $fragments Checkout fragments.
 * @return array<string, string>
 */
function freya_checkout_ywpar_rewards_fragments($fragments)
{
	if (!freya_checkout_ywpar_is_active()) {
		return $fragments;
	}

	$fragments['#yith-par-message-reward-cart'] = freya_checkout_ywpar_rewards_message_html();

	return $fragments;
}
add_filter('woocommerce_update_order_review_fragments', 'freya_checkout_ywpar_rewards_fragments', 15);

/**
 * After YITH applies points, persist/recalculate the cart before checkout refresh AJAX runs.
 *
 * @return void
 */
function freya_checkout_ywpar_persist_applied_points()
{
	if (!isset($_POST['ywpar_input_points_check'], $_POST['ywpar_input_points'])) {
		return;
	}

	if (empty($_POST['ywpar_input_points_check']) || empty($_POST['ywpar_input_points'])) {
		return;
	}

	if (function_exists('freya_checkout_sync_cart_discount_totals')) {
		freya_checkout_sync_cart_discount_totals();
	}

	if (function_exists('WC') && WC()->session && is_callable(array(WC()->session, 'save_data'))) {
		WC()->session->save_data();
	}
}
add_action('wp_loaded', 'freya_checkout_ywpar_persist_applied_points', 35);

/**
 * Expected YITH redeem coupon code for the logged-in customer.
 *
 * @return string
 */
function freya_checkout_ywpar_expected_coupon_code()
{
	if (!function_exists('yith_points') || !is_user_logged_in()) {
		return '';
	}

	return (string) apply_filters(
		'ywpar_coupon_code',
		yith_points()->redeeming->get_coupon_code_prefix() . '_' . get_current_user_id(),
		yith_points()->redeeming->get_coupon_code_prefix()
	);
}

/**
 * Whether a coupon code is a YITH points redeem coupon.
 *
 * @param string $code Coupon code.
 * @return bool
 */
function freya_checkout_ywpar_is_redeeming_code($code)
{
	if (!is_string($code) || $code === '' || !function_exists('yith_points')) {
		return false;
	}

	$prefix = yith_points()->redeeming->get_coupon_code_prefix() . '_';
	return stripos($code, $prefix) === 0;
}

/**
 * Whether the session still holds an active YITH points redemption.
 *
 * @return bool
 */
function freya_checkout_ywpar_session_has_redemption()
{
	if (!function_exists('WC') || !WC()->session) {
		return false;
	}

	$discount = max(0, (float) WC()->session->get('ywpar_coupon_code_discount'));
	if ($discount <= 0) {
		return false;
	}

	$posted = WC()->session->get('ywpar_coupon_posted');
	if (!is_array($posted)) {
		return false;
	}

	if (!empty($posted['ywpar_input_points_check']) && !empty($posted['ywpar_input_points'])) {
		return true;
	}

	return !empty($posted['ywpar_max_discount']);
}

/**
 * Provide virtual YITH coupon data when the dynamic shop_coupon post was deleted.
 *
 * @param array<string, mixed>|false $data   Coupon data from the database.
 * @param string                     $code   Coupon code.
 * @param WC_Coupon                  $coupon Coupon instance.
 * @return array<string, mixed>|false
 */
function freya_checkout_ywpar_shop_coupon_data($data, $code, $coupon)
{
	if (false !== $data || !freya_checkout_ywpar_is_redeeming_code($code)) {
		return $data;
	}

	if (!is_user_logged_in() || !function_exists('WC') || !WC()->session) {
		return $data;
	}

	$expected = freya_checkout_ywpar_expected_coupon_code();
	if ($expected === '' || strtolower($code) !== strtolower($expected)) {
		return $data;
	}

	if (!freya_checkout_ywpar_session_has_redemption()) {
		return $data;
	}

	$discount = max(0, (float) WC()->session->get('ywpar_coupon_code_discount'));
	if ($discount <= 0) {
		return $data;
	}

	return array(
		'discount_type'        => 'fixed_cart',
		'amount'               => (string) $discount,
		'individual_use'       => false,
		'usage_limit'          => 0,
		'usage_limit_per_user' => 0,
		'exclude_sale_items'   => false,
		'free_shipping'        => false,
		'meta_data'            => array(
			array(
				'key'   => 'ywpar_coupon',
				'value' => '1',
			),
		),
	);
}
add_filter('woocommerce_get_shop_coupon_data', 'freya_checkout_ywpar_shop_coupon_data', 10, 3);

/**
 * YITH redeem coupons must stack with checkout promos and apply to sale subscription prices.
 *
 * @param bool      $value  Current property value.
 * @param WC_Coupon $coupon Coupon instance.
 * @return bool
 */
function freya_checkout_ywpar_coupon_individual_use($value, $coupon)
{
	if ($coupon instanceof WC_Coupon && freya_checkout_ywpar_is_redeeming_code($coupon->get_code())) {
		return false;
	}

	return $value;
}
add_filter('woocommerce_coupon_get_individual_use', 'freya_checkout_ywpar_coupon_individual_use', 20, 2);

/**
 * @param bool      $value  Current property value.
 * @param WC_Coupon $coupon Coupon instance.
 * @return bool
 */
function freya_checkout_ywpar_coupon_exclude_sale_items($value, $coupon)
{
	if ($coupon instanceof WC_Coupon && freya_checkout_ywpar_is_redeeming_code($coupon->get_code())) {
		return false;
	}

	return $value;
}
add_filter('woocommerce_coupon_get_exclude_sale_items', 'freya_checkout_ywpar_coupon_exclude_sale_items', 20, 2);

/**
 * @param int       $value  Current usage limit.
 * @param WC_Coupon $coupon Coupon instance.
 * @return int
 */
function freya_checkout_ywpar_coupon_usage_limit($value, $coupon)
{
	if ($coupon instanceof WC_Coupon && freya_checkout_ywpar_is_redeeming_code($coupon->get_code())) {
		return 0;
	}

	return $value;
}
add_filter('woocommerce_coupon_get_usage_limit', 'freya_checkout_ywpar_coupon_usage_limit', 20, 2);

/**
 * Virtual YITH coupons from woocommerce_get_shop_coupon_data never receive meta (WC skips meta_data in set_props).
 * YITH identifies redeem coupons via ywpar_coupon meta when deducting points on order completion.
 *
 * @param mixed     $value  Meta value.
 * @param WC_Coupon $coupon Coupon instance.
 * @return mixed
 */
function freya_checkout_ywpar_coupon_ywpar_meta($value, $coupon)
{
	if ($value !== '' && $value !== null && $value !== false) {
		return $value;
	}

	if ($coupon instanceof WC_Coupon && freya_checkout_ywpar_is_redeeming_code($coupon->get_code())) {
		return '1';
	}

	return $value;
}
add_filter('woocommerce_coupon_get_ywpar_coupon', 'freya_checkout_ywpar_coupon_ywpar_meta', 10, 2);

/**
 * Whether the order used a YITH points redeem coupon code.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function freya_checkout_ywpar_order_has_redeeming_code($order)
{
	if (!$order instanceof WC_Order) {
		return false;
	}

	foreach ($order->get_coupon_codes() as $code) {
		if (freya_checkout_ywpar_is_redeeming_code($code)) {
			return true;
		}
	}

	return false;
}

/**
 * Copy session redemption data onto the order before YITH reads it.
 *
 * @param WC_Order $order Order being created.
 * @return void
 */
function freya_checkout_ywpar_persist_order_meta_on_create($order)
{
	if (!$order instanceof WC_Order || !function_exists('WC') || !WC()->session) {
		return;
	}

	if (!freya_checkout_ywpar_session_has_redemption() && !freya_checkout_ywpar_order_has_redeeming_code($order)) {
		return;
	}

	$points = max(0, (int) WC()->session->get('ywpar_coupon_code_points'));
	$amount = max(0, (float) WC()->session->get('ywpar_coupon_code_discount'));

	if ($points <= 0 && $amount <= 0) {
		return;
	}

	if ($points > 0 && !$order->get_meta('_ywpar_coupon_points')) {
		$order->update_meta_data('_ywpar_coupon_points', $points);
	}

	if ($amount > 0 && !$order->get_meta('_ywpar_coupon_amount')) {
		$order->update_meta_data('_ywpar_coupon_amount', $amount);
	}
}
add_action('woocommerce_checkout_create_order', 'freya_checkout_ywpar_persist_order_meta_on_create', 5, 1);

/**
 * Backup session redemption meta in case YITH add_order_meta skipped recognition.
 *
 * @param int $order_id Order ID.
 * @return void
 */
function freya_checkout_ywpar_backup_order_meta($order_id)
{
	$order = wc_get_order($order_id);
	if (!$order instanceof WC_Order || !function_exists('WC') || !WC()->session) {
		return;
	}

	if (!freya_checkout_ywpar_order_has_redeeming_code($order) && !freya_checkout_ywpar_session_has_redemption()) {
		return;
	}

	$points = max(0, (int) WC()->session->get('ywpar_coupon_code_points'));
	$amount = max(0, (float) WC()->session->get('ywpar_coupon_code_discount'));
	$changed = false;

	if ($points > 0 && !$order->get_meta('_ywpar_coupon_points')) {
		$order->update_meta_data('_ywpar_coupon_points', $points);
		$changed = true;
	}

	if ($amount > 0 && !$order->get_meta('_ywpar_coupon_amount')) {
		$order->update_meta_data('_ywpar_coupon_amount', $amount);
		$changed = true;
	}

	if ($changed) {
		$order->save();
	}
}
add_action('woocommerce_checkout_update_order_meta', 'freya_checkout_ywpar_backup_order_meta', 5);

/**
 * Clear YITH redeem session data after the order has consumed it.
 *
 * YITH copies session values to order meta at priority 10 and deducts points at 20.
 * It does not unset ywpar_coupon_posted / ywpar_coupon_code_* afterward, so our
 * checkout compatibility layer would re-apply the discount on the next visit.
 *
 * @param int $order_id Order ID.
 * @return void
 */
function freya_checkout_ywpar_clear_redemption_session($order_id)
{
	if (!$order_id || !function_exists('WC') || !WC()->session) {
		return;
	}

	$order = wc_get_order($order_id);
	if (!$order instanceof WC_Order) {
		return;
	}

	if (!freya_checkout_ywpar_order_has_redeeming_code($order) && !freya_checkout_ywpar_session_has_redemption()) {
		return;
	}

	if (WC()->cart) {
		$code = freya_checkout_ywpar_expected_coupon_code();
		if ($code !== '' && WC()->cart->has_discount($code)) {
			WC()->cart->remove_coupon($code);
		}
	}

	WC()->session->set('ywpar_coupon_posted', null);
	WC()->session->set('ywpar_coupon_code_discount', null);
	WC()->session->set('ywpar_coupon_code_points', null);
	WC()->session->set('ywpar_automatically_applied', false);
	WC()->session->set('ywpar_automatically_applied_time', '');

	if (is_callable(array(WC()->session, 'save_data'))) {
		WC()->session->save_data();
	}
}
add_action('woocommerce_checkout_update_order_meta', 'freya_checkout_ywpar_clear_redemption_session', 25);
add_action('woocommerce_store_api_checkout_update_order_meta', 'freya_checkout_ywpar_clear_redemption_session', 25);

/**
 * Persist the dynamic coupon post so YITH can recognize it after checkout.
 *
 * @param string $code Coupon code.
 * @return void
 */
function freya_checkout_ywpar_persist_coupon_post($code)
{
	if (!function_exists('yith_points') || !function_exists('WC') || !WC()->session) {
		return;
	}

	$posted = WC()->session->get('ywpar_coupon_posted');
	if (!is_array($posted)) {
		return;
	}

	$coupon = new WC_Coupon($code);
	if ($coupon->get_id() > 0 && $coupon->get_meta('ywpar_coupon')) {
		return;
	}

	yith_points()->redeeming->apply_discount_calculation($posted, true);
}

/**
 * Re-create or refresh the YITH coupon before WooCommerce validates cart coupons at checkout.
 *
 * @return void
 */
function freya_checkout_ywpar_ensure_coupon_valid()
{
	static $running = false;

	if ($running) {
		return;
	}

	if (!function_exists('yith_points') || !function_exists('WC') || !WC()->cart || !WC()->session || !is_user_logged_in()) {
		return;
	}

	if (!freya_checkout_ywpar_session_has_redemption()) {
		return;
	}

	$posted   = WC()->session->get('ywpar_coupon_posted');
	$expected = freya_checkout_ywpar_expected_coupon_code();
	if (!is_array($posted) || $expected === '') {
		return;
	}

	$applied = WC()->cart->get_applied_coupons();
	$in_cart = in_array(strtolower($expected), array_map('strtolower', $applied), true);

	$needs_reapply = !$in_cart;
	if (!$needs_reapply) {
		$coupon = new WC_Coupon($expected);
		$needs_reapply = $coupon->get_amount() <= 0 || !ywpar_coupon_is_valid($coupon, WC()->cart);
	}

	if (!$needs_reapply) {
		$coupon = new WC_Coupon($expected);
		if ($coupon->get_id() === 0 || $coupon->get_virtual() || '' === $coupon->get_meta('ywpar_coupon')) {
			$running = true;
			freya_checkout_ywpar_persist_coupon_post($expected);
			WC()->cart->calculate_totals();
			$running = false;
		}
		return;
	}

	$running = true;
	yith_points()->redeeming->apply_discount_calculation($posted, true);
	WC()->cart->calculate_totals();
	$running = false;
}
add_action('woocommerce_check_cart_items', 'freya_checkout_ywpar_ensure_coupon_valid', 0);
add_action('woocommerce_checkout_create_order', 'freya_checkout_ywpar_persist_coupon_before_order', 8);

/**
 * Save the dynamic coupon post immediately before the order is written.
 *
 * @param WC_Order $order Order being created.
 * @return void
 */
function freya_checkout_ywpar_persist_coupon_before_order($order)
{
	if (!$order instanceof WC_Order || !function_exists('WC') || !WC()->session) {
		return;
	}

	if (!freya_checkout_ywpar_session_has_redemption()) {
		return;
	}

	freya_checkout_ywpar_persist_coupon_post(freya_checkout_ywpar_expected_coupon_code());
}

/**
 * Re-apply a points discount after plan-switch cart rebuild (empty_cart clears coupons).
 *
 * @return void
 */
function freya_checkout_ywpar_restore_after_plan_switch()
{
	if (!function_exists('yith_points') || !function_exists('WC') || !WC()->cart || !WC()->session) {
		return;
	}

	$posted = WC()->session->get('ywpar_coupon_posted');
	if (!is_array($posted) || empty($posted['ywpar_input_points'])) {
		return;
	}

	if (function_exists('ywpar_cart_has_redeeming_coupon') && ywpar_cart_has_redeeming_coupon()) {
		yith_points()->redeeming->update_discount();
	} else {
		yith_points()->redeeming->apply_discount_calculation($posted, true);
	}

	WC()->cart->calculate_totals();
}

/**
 * Detach the default hook before the checkout template fires woocommerce_before_checkout_form.
 *
 * @return void
 */
function freya_checkout_ywpar_prepare_template_hooks()
{
	if (!freya_checkout_ywpar_is_active()) {
		return;
	}

	freya_checkout_ywpar_detach_default_rewards_hook();
}
add_action('template_redirect', 'freya_checkout_ywpar_prepare_template_hooks', 31);

/**
 * Minimal styling so the YITH form matches the Freya coupon row.
 *
 * @return void
 */
function freya_checkout_ywpar_enqueue_styles()
{
	if (!freya_checkout_ywpar_is_active()) {
		return;
	}

	$css = '
		body.freya-checkout-b.freya-checkout-default-shell .freya-checkout-section #yith-par-message-reward-cart {
			max-width: var(--freya-default-max-width, 100%);
			margin: 0 auto 10px;
			padding: 12px 14px;
			border: 0.5px solid #E5E0D6;
			border-radius: 8px;
			background: #fff;
			font-size: 14px;
			line-height: 1.45;
			color: #16213E;
		}
		body.freya-checkout-b.freya-checkout-default-shell .freya-checkout-section #yith-par-message-reward-cart .ywpar_apply_discounts {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			gap: 8px;
			margin: 0;
		}
		body.freya-checkout-b.freya-checkout-default-shell .freya-checkout-section #yith-par-message-reward-cart #ywpar-points-max {
			width: 72px;
			border: 0.5px solid #E5E0D6;
			border-radius: 8px;
			padding: 8px 10px;
			font-size: 16px;
			margin: 0;
		}
		body.freya-checkout-b.freya-checkout-default-shell .freya-checkout-section #yith-par-message-reward-cart #ywpar_apply_discounts {
			background: transparent;
			color: #6B7280;
			border: 0.5px solid #E5E0D6;
			border-radius: 8px;
			padding: 11px 16px;
			font-size: 13px;
			font-weight: 500;
			line-height: 1.25;
			margin: 0;
		}
	';

	wp_add_inline_style('ywpar_frontend', $css);
}
add_action('wp_enqueue_scripts', 'freya_checkout_ywpar_enqueue_styles', 30);
