<?php
/**
 * Prevent referral / affiliate rewards on subscription renewals.
 *
 * @package Freya_YWPAR_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

class Freya_YWPAR_Referral_Guard {

	/** @var self */
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'wc_subscriptions_renewal_order_data', array( $this, 'strip_referral_meta_from_renewals' ), 20 );
		add_action( 'woocommerce_subscription_payment_complete', array( $this, 'clear_referral_meta_from_subscription' ), 20, 1 );
		add_action( 'ywpar_added_earned_points_to_order', array( $this, 'block_referral_purchase_on_renewals' ), 5, 1 );
		add_filter( 'yith_wcaf_create_order_commissions', array( $this, 'block_affiliate_commissions_on_renewals' ), 5, 4 );
	}

	/**
	 * Stop referral purchase meta being copied onto renewal orders.
	 *
	 * @param array $order_meta Meta to copy.
	 * @return array
	 */
	public function strip_referral_meta_from_renewals( $order_meta ) {
		$keys = array(
			'ywpar_referral_purchase',
			'_yith_wcaf_referral',
			'_yith_wcaf_referral_origin',
		);

		foreach ( $keys as $key ) {
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

		$keys = array(
			'ywpar_referral_purchase',
			'_yith_wcaf_referral',
			'_yith_wcaf_referral_origin',
		);

		$changed = false;
		foreach ( $keys as $key ) {
			if ( '' !== $subscription->get_meta( $key, true ) ) {
				$subscription->delete_meta_data( $key );
				$changed = true;
			}
		}

		if ( $changed ) {
			$subscription->save();
		}

		$parent_id = $subscription->get_parent_id();
		if ( $parent_id ) {
			$parent = wc_get_order( $parent_id );
			if ( $parent ) {
				$ref = $parent->get_meta( 'ywpar_referral_purchase', true );
				if ( is_array( $ref ) && empty( $ref['used'] ) ) {
					$ref['used'] = true;
					$parent->update_meta_data( 'ywpar_referral_purchase', $ref );
					$parent->save();
				}
			}
		}
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public function block_referral_purchase_on_renewals( $order ) {
		if ( ! $order instanceof WC_Order || ! function_exists( 'wcs_order_contains_renewal' ) ) {
			return;
		}

		if ( ! wcs_order_contains_renewal( $order ) ) {
			return;
		}

		if ( $order->get_meta( 'ywpar_referral_purchase', true ) ) {
			$order->delete_meta_data( 'ywpar_referral_purchase' );
			$order->save();
		}
	}

	/**
	 * @param bool   $result Current result.
	 * @param int    $order_id Order ID.
	 * @param string $token Affiliate token.
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
