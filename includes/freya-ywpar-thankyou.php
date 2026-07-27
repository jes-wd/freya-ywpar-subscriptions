<?php
/**
 * YITH Points & Rewards data for the checkout thank-you page.
 *
 * @package Freya_YWPAR_Subscriptions
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether YITH earning is available for thank-you rewards UI.
 *
 * @return bool
 */
function freya_thankyou_ywpar_is_active()
{
    return function_exists('ywpar_get_option')
        && function_exists('yith_points')
        && 'yes' === ywpar_get_option('enable_points_upon_sales');
}

/**
 * Points earned (or expected) for an order on the thank-you page.
 *
 * @param WC_Order $order Order.
 * @return int
 */
function freya_thankyou_get_order_points_earned($order)
{
    if (!$order instanceof WC_Order) {
        return 0;
    }

    $points = (int) $order->get_meta('_ywpar_points_earned');
    if ($points <= 0) {
        $points = (int) $order->get_meta('ywpar_points_from_cart');
    }

    return max(0, $points);
}

/**
 * Human-readable fixed redeem conversion label, e.g. "100 = $1".
 *
 * @param YITH_WC_Points_Rewards_Customer $customer Customer.
 * @param string                        $currency Currency code.
 * @return string
 */
function freya_thankyou_get_redeem_conversion_label($customer, $currency = '')
{
    if (!function_exists('yith_points') || 'yes' !== ywpar_get_option('enable_rewards_points')) {
        return '';
    }

    $currency = $currency ? $currency : get_woocommerce_currency();
    $method   = yith_points()->redeeming->get_conversion_method();
    $rate     = yith_points()->redeeming->get_conversion_rate_rewards($currency, $customer);

    if ('fixed' === $method && !empty($rate['points']) && !empty($rate['money'])) {
        return sprintf(
            '%s&nbsp;=&nbsp;%s',
            esc_html(number_format_i18n((int) $rate['points'])),
            wp_strip_all_tags(wc_price((float) $rate['money'], array('currency' => $currency)))
        );
    }

    if (!empty($rate['points']) && !empty($rate['discount'])) {
        return sprintf(
            '%s %s = %s%%',
            esc_html(number_format_i18n((int) $rate['points'])),
            esc_html(ywpar_get_option('points_label_plural', 'points')),
            esc_html((string) (int) $rate['discount'])
        );
    }

    return '';
}

/**
 * Subscription ID linked to an order, when available.
 *
 * @param WC_Order $order Order.
 * @return int
 */
function freya_thankyou_get_subscription_id_for_order($order)
{
    if (!$order instanceof WC_Order || !function_exists('wcs_get_subscriptions_for_order')) {
        return 0;
    }

    $subscriptions = wcs_get_subscriptions_for_order($order->get_id(), array('order_type' => 'parent'));
    if (empty($subscriptions)) {
        $subscriptions = wcs_get_subscriptions_for_order($order->get_id(), array('order_type' => 'any'));
    }

    if (empty($subscriptions)) {
        return 0;
    }

    $subscription = reset($subscriptions);
    return $subscription ? (int) $subscription->get_id() : 0;
}

/**
 * Referral link and share copy for a customer (thank-you page, my account, etc.).
 *
 * @param int $user_id User ID.
 * @return array<string, mixed>
 */
function freya_get_ywpar_referral_context($user_id = 0)
{
    $context = array(
        'show'                     => false,
        'referral_link'            => '',
        'referral_purchase_points' => 0,
        'referral_share_heading'   => '',
        'points_label_plural'      => 'points',
        'share_message'            => '',
    );

    if (!function_exists('ywpar_get_option') || $user_id <= 0 || !class_exists('YITH_WC_Points_Rewards_Referral')) {
        return $context;
    }

    $customer = function_exists('ywpar_get_customer') ? ywpar_get_customer($user_id) : null;
    if (!$customer || !$customer->is_enabled()) {
        return $context;
    }

    $points_label             = ywpar_get_option('points_label_plural', 'points');
    $referral_link            = '';
    $referral_purchase_points = 0;

    $referral_enabled = 'yes' === ywpar_get_option('enable_points_on_referral_registration_exp')
        || 'yes' === ywpar_get_option('enable_points_on_referral_purchase_exp');
    if ($referral_enabled) {
        $referral_link = YITH_WC_Points_Rewards_Referral::get_referral_link($user_id);
    }
    if ('yes' === ywpar_get_option('enable_points_on_referral_purchase_exp')) {
        $referral_purchase_points = max(0, (int) ywpar_get_option('points_referral_purchase', 0));
    }

    if ('' === $referral_link) {
        return $context;
    }

    $share_message = apply_filters(
        'freya_my_account_share_message',
        apply_filters(
            'freya_thankyou_share_message',
            __('I\'ve been using Freya for my weight loss meds and honestly love it — thought of you 💛 Here\'s my link to get started:', 'freya-theme-logic'),
            null,
            $customer
        ),
        $customer
    );

    $referral_share_heading = $referral_purchase_points > 0
        ? sprintf(
            /* translators: 1: points amount HTML, 2: points label (e.g. points) */
            __('Share Freya.<br>Earn %1$s %2$s per referral.', 'freya-theme-logic'),
            sprintf(
                '<span class="fr-share-points-amt">%s</span>',
                esc_html(number_format_i18n($referral_purchase_points))
            ),
            esc_html($points_label)
        )
        : __('Share Freya.<br>Earn toward every refill.', 'freya-theme-logic');

    $context['show']                     = true;
    $context['referral_link']            = $referral_link;
    $context['referral_purchase_points'] = $referral_purchase_points;
    $context['referral_share_heading']   = $referral_share_heading;
    $context['points_label_plural']      = $points_label;
    $context['share_message']            = $share_message;

    return $context;
}

/**
 * Build thank-you rewards context from a completed order.
 *
 * @param WC_Order $order Order.
 * @return array<string, mixed>
 */
function freya_get_thankyou_ywpar_context($order)
{
    $context = array(
        'show'                    => false,
        'points_earned'           => 0,
        'points_worth'            => '',
        'referral_link'           => '',
        'referral_purchase_points' => 0,
        'referral_share_heading'  => '',
        'points_per_order'        => 0,
        'earn_on_renewal'         => false,
        'redeem_enabled'          => false,
        'redeem_conversion_label' => '',
        'points_label_plural'     => 'points',
        'share_message'           => '',
        'subscription_id'         => 0,
        'reward_subline_html'     => '',
        'how_order_label'         => '',
    );

    if (!$order instanceof WC_Order || !freya_thankyou_ywpar_is_active()) {
        return $context;
    }

    $customer = ywpar_get_point_customer_from_order($order);
    if (!$customer || !$customer->is_enabled()) {
        return $context;
    }

    $points_earned = freya_thankyou_get_order_points_earned($order);
    if ($points_earned <= 0) {
        return $context;
    }

    $currency          = $order->get_currency();
    $points_label      = ywpar_get_option('points_label_plural', 'points');
    $earn_on_renewal   = 'yes' === ywpar_get_option('earn_points_on_renew');
    $redeem_enabled    = 'yes' === ywpar_get_option('enable_rewards_points');
    $points_worth               = '';
    $referral_link              = '';
    $referral_purchase_points   = 0;
    $user_id                    = (int) $order->get_customer_id();

    if ($redeem_enabled && $customer->is_enabled('redeem')) {
        $points_worth = yith_points()->redeeming->calculate_price_worth_from_points($points_earned, $customer, true);
    }

    $referral_context         = array();
    if ($user_id > 0) {
        $referral_context = freya_get_ywpar_referral_context($user_id);
        $referral_link            = $referral_context['referral_link'];
        $referral_purchase_points = $referral_context['referral_purchase_points'];
    }

    $worth_display = $points_worth ? wp_strip_all_tags($points_worth) : '';
    if ($worth_display && $earn_on_renewal) {
        $reward_subline_html = sprintf(
            /* translators: 1: dollar value, 2: points per refill */
            __('Just for placing your first order. That\'s <b>%1$s</b> toward your next refill — and every refill after earns you another <b>%2$s</b>.', 'freya-theme-logic'),
            esc_html($worth_display),
            esc_html(number_format_i18n($points_earned))
        );
        $how_order_label = __('points for your first order & every refill', 'freya-theme-logic');
    } elseif ($worth_display) {
        $reward_subline_html = sprintf(
            /* translators: %s: dollar value */
            __('Just for placing your order. That\'s <b>%1$s</b> toward your next purchase.', 'freya-theme-logic'),
            esc_html($worth_display)
        );
        $how_order_label = __('points for your order', 'freya-theme-logic');
    } else {
        $reward_subline_html = $earn_on_renewal
            ? sprintf(
                /* translators: %s: points amount */
                __('Just for placing your first order — and every refill after earns you another <b>%s</b>.', 'freya-theme-logic'),
                esc_html(number_format_i18n($points_earned))
            )
            : __('Just for placing your order.', 'freya-theme-logic');
        $how_order_label = $earn_on_renewal
            ? __('points for your first order & every refill', 'freya-theme-logic')
            : __('points for your order', 'freya-theme-logic');
    }

    $share_message = apply_filters(
        'freya_thankyou_share_message',
        __('I\'ve been using Freya for my weight loss meds and honestly love it — thought of you 💛 Here\'s my link to get started:', 'freya-theme-logic'),
        $order,
        $customer
    );

    $referral_share_heading = $referral_purchase_points > 0
        ? sprintf(
            /* translators: 1: points amount HTML, 2: points label (e.g. points) */
            __('Share Freya.<br>Earn %1$s %2$s.', 'freya-theme-logic'),
            sprintf(
                '<span class="fr-share-points-amt">%s</span>',
                esc_html(number_format_i18n($referral_purchase_points))
            ),
            esc_html($points_label)
        )
        : __('Share Freya.<br>Earn toward every refill.', 'freya-theme-logic');

    $context['show']                    = true;
    $context['points_earned']           = $points_earned;
    $context['points_worth']            = $worth_display;
    $context['referral_link']           = $referral_link;
    $context['referral_purchase_points'] = $referral_purchase_points;
    $context['referral_share_heading']  = $referral_share_heading;
    $context['points_per_order']        = $points_earned;
    $context['earn_on_renewal']         = $earn_on_renewal;
    $context['redeem_enabled']          = $redeem_enabled;
    $context['redeem_conversion_label'] = freya_thankyou_get_redeem_conversion_label($customer, $currency);
    $context['points_label_plural']     = $points_label;
    $context['share_message']           = $share_message;
    $context['subscription_id']         = freya_thankyou_get_subscription_id_for_order($order);
    $context['reward_subline_html']     = $reward_subline_html;
    $context['how_order_label']         = $how_order_label;

    return $context;
}
