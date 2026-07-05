<?php
/**
 * Prevent YITH points from being earned on $0 orders.
 *
 * YITH calculates earnable points from line item subtotals, so prepaid
 * fulfillment renewals and other zero-total orders can still award points
 * unless we block it explicitly.
 *
 * @package Freya_YWPAR_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

class Freya_YWPAR_Zero_Order_Guard {

	/** @var self */
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'ywpar_add_order_points', array( $this, 'skip_zero_dollar_orders' ), 10, 2 );
		add_filter( 'ywpar_earned_total_points_by_order', array( $this, 'zero_points_for_zero_dollar_orders' ), 10, 2 );
		add_filter( 'ywpar_calculate_points_on_cart', array( $this, 'zero_points_for_zero_dollar_cart' ), 10, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'clear_saved_cart_points_on_zero_order' ), 15, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'clear_saved_cart_points_on_zero_order' ), 15, 1 );
	}

	/**
	 * @param WC_Order|int|null $order Order or ID.
	 * @return bool
	 */
	public static function is_zero_dollar_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order ) {
			return false;
		}

		return (float) $order->get_total() <= 0.0001;
	}

	/**
	 * @param bool $skip Whether to skip awarding points.
	 * @param int  $order_id Order ID.
	 * @return bool
	 */
	public function skip_zero_dollar_orders( $skip, $order_id ) {
		if ( $skip ) {
			return $skip;
		}

		return self::is_zero_dollar_order( $order_id );
	}

	/**
	 * @param int      $points Points to award.
	 * @param WC_Order $order Order.
	 * @return int
	 */
	public function zero_points_for_zero_dollar_orders( $points, $order ) {
		if ( self::is_zero_dollar_order( $order ) ) {
			return 0;
		}

		return $points;
	}

	/**
	 * @param int $points Points calculated for the cart.
	 * @return int
	 */
	public function zero_points_for_zero_dollar_cart( $points ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $points;
		}

		if ( (float) WC()->cart->get_total( 'edit' ) <= 0.0001 ) {
			return 0;
		}

		return $points;
	}

	/**
	 * Runs after YITH saves ywpar_points_from_cart at checkout.
	 *
	 * @param int|WC_Order $order Order or ID.
	 */
	public function clear_saved_cart_points_on_zero_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}

		if ( ! self::is_zero_dollar_order( $order ) ) {
			return;
		}

		if ( (int) $order->get_meta( 'ywpar_points_from_cart', true ) !== 0 ) {
			$order->update_meta_data( 'ywpar_points_from_cart', 0 );
			$order->save();
		}
	}
}
