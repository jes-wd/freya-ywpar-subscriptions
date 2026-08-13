<?php
/**
 * Keep YITH referral purchase meta on a customer's first shop order only.
 * Also block affiliate commissions and referral rewards on subscription renewals.
 *
 * @package Freya_YWPAR_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

class Freya_YWPAR_Referral_Guard {

	/** @var self */
	private static $instance;

	/**
	 * Order meta keys that must never leave the first (parent) order.
	 *
	 * @return string[]
	 */
	private static function referral_meta_keys() {
		return array(
			'ywpar_referral_purchase',
			'_yith_wcaf_referral',
			'_yith_wcaf_referral_origin',
		);
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Do not copy referral meta onto subscriptions / renewals / resubscribes.
		add_filter( 'wc_subscriptions_subscription_data', array( $this, 'strip_referral_meta_from_copies' ), 20 );
		add_filter( 'wc_subscriptions_renewal_order_data', array( $this, 'strip_referral_meta_from_copies' ), 20 );
		add_filter( 'wc_subscriptions_resubscribe_order_data', array( $this, 'strip_referral_meta_from_copies' ), 20 );

		add_action( 'woocommerce_subscription_payment_complete', array( $this, 'clear_referral_meta_from_subscription' ), 20, 1 );

		// YITH writes ywpar_referral_purchase on ywpar_saved_points_earned_from_cart @10 — strip after if not first order.
		add_action( 'ywpar_saved_points_earned_from_cart', array( $this, 'strip_referral_meta_unless_first_order' ), 20, 1 );

		// Before YITH awards referral points (@10), remove meta on any non-first order.
		add_action( 'ywpar_added_earned_points_to_order', array( $this, 'block_referral_purchase_unless_first_order' ), 5, 1 );

		// Guest path: YITH awards on completed via assign_referral_points_for_guest_orders @10.
		add_action( 'woocommerce_order_status_completed', array( $this, 'strip_referral_meta_on_completed_unless_first' ), 5, 1 );

		add_filter( 'yith_wcaf_create_order_commissions', array( $this, 'block_affiliate_commissions_on_renewals' ), 5, 4 );
	}

	/**
	 * Whether this is the customer's first shop_order by creation time.
	 *
	 * Renewals / resubscribes are never first. Registered customers prefer
	 * freya_utm_is_first_time_customer_order when available; guests are matched by billing email.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_first_customer_order( $order ) {
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
			return false;
		}

		if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
			return false;
		}

		if ( function_exists( 'wcs_order_contains_resubscribe' ) && wcs_order_contains_resubscribe( $order ) ) {
			return false;
		}

		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 && function_exists( 'freya_utm_is_first_time_customer_order' ) ) {
			return (bool) freya_utm_is_first_time_customer_order( $order );
		}

		$created = $order->get_date_created();
		if ( ! $created ) {
			return false;
		}

		$before = $created->date( 'Y-m-d H:i:s' );
		$args   = array(
			'type'         => 'shop_order',
			'date_created' => '<' . $before,
			'limit'        => 1,
			'return'       => 'ids',
			'orderby'      => 'date',
			'order'        => 'ASC',
		);

		if ( $customer_id > 0 ) {
			$args['customer_id'] = $customer_id;
		} else {
			$email = $order->get_billing_email();
			if ( '' === $email ) {
				return false;
			}
			$args['billing_email'] = $email;
		}

		$previous = wc_get_orders( $args );

		return empty( $previous );
	}

	/**
	 * Remove referral keys from WCS meta-copy payloads.
	 *
	 * @param array $order_meta Meta to copy.
	 * @return array
	 */
	public function strip_referral_meta_from_copies( $order_meta ) {
		if ( ! is_array( $order_meta ) ) {
			return $order_meta;
		}

		foreach ( self::referral_meta_keys() as $key ) {
			unset( $order_meta[ $key ] );
		}

		return $order_meta;
	}

	/**
	 * After the first successful subscription payment, remove referral meta from the
	 * subscription so it cannot affect future renewal processing.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 */
	public function clear_referral_meta_from_subscription( $subscription ) {
		if ( ! $subscription instanceof WC_Subscription ) {
			return;
		}

		$changed = false;
		foreach ( self::referral_meta_keys() as $key ) {
			if ( '' !== $subscription->get_meta( $key, true ) ) {
				$subscription->delete_meta_data( $key );
				$changed = true;
			}
		}

		if ( $changed ) {
			$subscription->save();
		}
	}

	/**
	 * Strip referral purchase meta when YITH has just written it on a non-first order.
	 *
	 * @param WC_Order $order Order.
	 */
	public function strip_referral_meta_unless_first_order( $order ) {
		$this->remove_ywpar_referral_purchase_if_not_first( $order );
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public function block_referral_purchase_unless_first_order( $order ) {
		$this->remove_ywpar_referral_purchase_if_not_first( $order );
	}

	/**
	 * @param int $order_id Order ID.
	 */
	public function strip_referral_meta_on_completed_unless_first( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$this->remove_ywpar_referral_purchase_if_not_first( $order );
		}
	}

	/**
	 * Delete ywpar_referral_purchase when the order is not the customer's first.
	 *
	 * @param WC_Order $order Order.
	 */
	private function remove_ywpar_referral_purchase_if_not_first( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( self::is_first_customer_order( $order ) ) {
			return;
		}

		if ( ! $order->get_meta( 'ywpar_referral_purchase', true ) ) {
			return;
		}

		$order->delete_meta_data( 'ywpar_referral_purchase' );
		$order->save();
	}

	/**
	 * @param bool   $result       Current result.
	 * @param int    $order_id     Order ID.
	 * @param string $token        Affiliate token.
	 * @param string $token_origin Token origin.
	 * @return bool
	 */
	public function block_affiliate_commissions_on_renewals( $result, $order_id, $token, $token_origin ) {
		unset( $token, $token_origin );

		if ( ! function_exists( 'wcs_order_contains_renewal' ) ) {
			return $result;
		}

		$order = wc_get_order( $order_id );
		if ( $order && wcs_order_contains_renewal( $order ) ) {
			return false;
		}

		return $result;
	}
}
