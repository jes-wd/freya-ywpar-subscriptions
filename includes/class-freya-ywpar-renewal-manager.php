<?php
/**
 * Reserve, apply, and consume YITH points on subscription renewals.
 *
 * @package Freya_YWPAR_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

class Freya_YWPAR_Renewal_Manager {

	const FEE_NAME = 'Points discount';

	const SUB_META_POINTS = '_freya_ywpar_loaded_points';
	const SUB_META_LOADED_AT = '_freya_ywpar_loaded_at';
	const SUB_META_LOAD_TOKEN = '_freya_ywpar_load_token';

	const ORDER_META_POINTS = '_freya_ywpar_loaded_points';
	const ORDER_META_DISCOUNT = '_freya_ywpar_loaded_discount';
	const ORDER_META_SUBSCRIPTION_ID = '_freya_ywpar_subscription_id';
	const ORDER_META_CONSUMED = '_freya_ywpar_points_consumed';

	const ACTION_RESERVED = 'renewal_points_reserved';
	const ACTION_RELEASED = 'renewal_points_released';
	const ACTION_REDEEMED = 'renewal_points_redeemed';

	/** @var self */
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'wcs_renewal_order_created', array( $this, 'apply_to_renewal_order' ), 20, 2 );
		add_filter( 'wc_subscriptions_renewal_order_data', array( $this, 'exclude_loaded_points_from_renewal_copy' ), 10, 1 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'maybe_apply_to_renewal_cart' ), 50 );
		add_filter( 'woocommerce_subscriptions_is_recurring_fee', array( $this, 'mark_points_fee_recurring' ), 10, 3 );

		add_action( 'woocommerce_payment_complete', array( $this, 'maybe_finalize_redemption' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_finalize_redemption' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_finalize_redemption' ), 20 );

		add_action( 'woocommerce_subscription_status_cancelled', array( $this, 'release_on_subscription_end' ), 10, 1 );
		add_action( 'woocommerce_subscription_status_expired', array( $this, 'release_on_subscription_end' ), 10, 1 );

		add_filter( 'yith_ywpar_action_label', array( $this, 'filter_action_labels' ), 10, 2 );
	}

	/**
	 * @param WC_Subscription|int $subscription Subscription or ID.
	 * @return int
	 */
	public function get_loaded_points( $subscription ) {
		$subscription = $this->normalize_subscription( $subscription );
		if ( ! $subscription ) {
			return 0;
		}
		return max( 0, (int) $subscription->get_meta( self::SUB_META_POINTS, true ) );
	}

	/**
	 * @param int $user_id User ID.
	 * @return WC_Subscription[]
	 */
	public function get_user_subscriptions_with_loaded_points( $user_id ) {
		if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return array();
		}

		$loaded = array();
		foreach ( wcs_get_users_subscriptions( $user_id ) as $subscription ) {
			if ( $this->get_loaded_points( $subscription ) > 0 ) {
				$loaded[] = $subscription;
			}
		}
		return $loaded;
	}

	/**
	 * @param int $user_id User ID.
	 * @return WC_Subscription[]
	 */
	public function get_eligible_subscriptions( $user_id ) {
		if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return array();
		}

		$eligible = array();
		foreach ( wcs_get_users_subscriptions( $user_id ) as $subscription ) {
			if ( ! $subscription->get_user_id() || (int) $subscription->get_user_id() !== (int) $user_id ) {
				continue;
			}
			if ( $subscription->has_status( array( 'active', 'on-hold', 'pending' ) ) ) {
				$eligible[] = $subscription;
			}
		}
		return $eligible;
	}

	/**
	 * @param int               $user_id User ID.
	 * @param WC_Subscription|int $subscription Subscription.
	 * @param int               $points Points to reserve.
	 * @return true|WP_Error
	 */
	public function load_points( $user_id, $subscription, $points ) {
		$subscription = $this->normalize_subscription( $subscription );
		$points       = (int) $points;

		if ( ! $subscription || (int) $subscription->get_user_id() !== (int) $user_id ) {
			return new WP_Error( 'freya_ywpar_invalid_subscription', __( 'Invalid subscription.', 'freya-ywpar-subscriptions' ) );
		}

		if ( ! $subscription->has_status( array( 'active', 'on-hold', 'pending' ) ) ) {
			return new WP_Error( 'freya_ywpar_subscription_inactive', __( 'Points can only be loaded onto an active subscription.', 'freya-ywpar-subscriptions' ) );
		}

		if ( $this->get_loaded_points( $subscription ) > 0 ) {
			return new WP_Error( 'freya_ywpar_already_loaded', __( 'Remove existing points from this subscription before loading a new amount.', 'freya-ywpar-subscriptions' ) );
		}

		$validation = $this->validate_points_amount( $user_id, $subscription, $points );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$customer = ywpar_get_customer( $user_id );
		if ( ! $customer || ! $customer->is_enabled( 'redeem' ) ) {
			return new WP_Error( 'freya_ywpar_redeem_disabled', __( 'You are not allowed to redeem points.', 'freya-ywpar-subscriptions' ) );
		}

		$token = wp_generate_uuid4();
		$subscription->update_meta_data( self::SUB_META_POINTS, $points );
		$subscription->update_meta_data( self::SUB_META_LOADED_AT, gmdate( 'Y-m-d H:i:s' ) );
		$subscription->update_meta_data( self::SUB_META_LOAD_TOKEN, $token );
		$subscription->save();

		$description = sprintf(
			/* translators: %1$d: subscription ID, %2$d: points */
			__( 'Reserved for subscription #%1$d renewal (%2$d points)', 'freya-ywpar-subscriptions' ),
			$subscription->get_id(),
			$points
		);

		$result = $customer->update_points(
			-$points,
			self::ACTION_RESERVED,
			array(
				'description' => $description,
				'info'        => array(
					'subscription_id' => $subscription->get_id(),
					'load_token'    => $token,
				),
			)
		);

		if ( ! $result ) {
			$this->clear_subscription_load( $subscription );
			return new WP_Error( 'freya_ywpar_reserve_failed', __( 'Could not reserve points. Please try again.', 'freya-ywpar-subscriptions' ) );
		}

		$this->sync_open_renewal_orders( $subscription );

		return true;
	}

	/**
	 * @param int               $user_id User ID.
	 * @param WC_Subscription|int $subscription Subscription.
	 * @return true|WP_Error
	 */
	public function remove_loaded_points( $user_id, $subscription ) {
		$subscription = $this->normalize_subscription( $subscription );
		$points       = $this->get_loaded_points( $subscription );

		if ( ! $subscription || (int) $subscription->get_user_id() !== (int) $user_id ) {
			return new WP_Error( 'freya_ywpar_invalid_subscription', __( 'Invalid subscription.', 'freya-ywpar-subscriptions' ) );
		}

		if ( $points <= 0 ) {
			return new WP_Error( 'freya_ywpar_nothing_loaded', __( 'No points are loaded on this subscription.', 'freya-ywpar-subscriptions' ) );
		}

		$customer = ywpar_get_customer( $user_id );
		if ( ! $customer ) {
			return new WP_Error( 'freya_ywpar_no_customer', __( 'Customer record not found.', 'freya-ywpar-subscriptions' ) );
		}

		$this->clear_discount_from_open_renewal_orders( $subscription );
		$this->clear_subscription_load( $subscription );

		$description = sprintf(
			/* translators: %1$d: subscription ID, %2$d: points */
			__( 'Released from subscription #%1$d renewal (%2$d points)', 'freya-ywpar-subscriptions' ),
			$subscription->get_id(),
			$points
		);

		$result = $customer->update_points(
			$points,
			self::ACTION_RELEASED,
			array(
				'description' => $description,
				'info'        => array(
					'subscription_id' => $subscription->get_id(),
				),
			)
		);

		if ( ! $result ) {
			return new WP_Error( 'freya_ywpar_release_failed', __( 'Could not release points. Please contact support.', 'freya-ywpar-subscriptions' ) );
		}

		return true;
	}

	/**
	 * @param int               $user_id User ID.
	 * @param WC_Subscription   $subscription Subscription.
	 * @param int               $points Points.
	 * @return true|WP_Error
	 */
	public function validate_points_amount( $user_id, $subscription, $points ) {
		$points = (int) $points;

		if ( $points <= 0 ) {
			return new WP_Error( 'freya_ywpar_invalid_points', __( 'Enter a valid number of points.', 'freya-ywpar-subscriptions' ) );
		}

		if ( 'yes' !== ywpar_get_option( 'enable_rewards_points' ) ) {
			return new WP_Error( 'freya_ywpar_redeem_off', __( 'Points redemption is currently disabled.', 'freya-ywpar-subscriptions' ) );
		}

		$customer = ywpar_get_customer( $user_id );
		if ( ! $customer || $customer->get_total_points() < $points ) {
			return new WP_Error( 'freya_ywpar_insufficient_points', __( 'You do not have enough points.', 'freya-ywpar-subscriptions' ) );
		}

		$min_discount = yith_points()->redeeming->get_min_discount_amount_to_redeem();
		if ( '' !== $min_discount && (float) $min_discount > 0 ) {
			$conversion = yith_points()->redeeming->get_conversion_rate_rewards( '', $customer );
			if ( ! empty( $conversion['points'] ) && ! empty( $conversion['money'] ) ) {
				$discount = ( $points / (float) $conversion['points'] ) * (float) $conversion['money'];
				if ( $discount < (float) $min_discount ) {
					return new WP_Error(
						'freya_ywpar_min_discount',
						sprintf(
							/* translators: %s: formatted minimum discount */
							__( 'The minimum discount is %s.', 'freya-ywpar-subscriptions' ),
							wc_price( $min_discount )
						)
					);
				}
			}
		}

		$discount = $this->calculate_discount_for_points( $points, $customer );
		if ( $discount <= 0 ) {
			return new WP_Error( 'freya_ywpar_zero_discount', __( 'These points do not convert to a discount.', 'freya-ywpar-subscriptions' ) );
		}

		$renewal_total = (float) $subscription->get_total();
		if ( $renewal_total > 0 && $discount > $renewal_total ) {
			return new WP_Error(
				'freya_ywpar_discount_exceeds_total',
				sprintf(
					/* translators: %s: formatted renewal total */
					__( 'Discount cannot exceed the renewal total of %s.', 'freya-ywpar-subscriptions' ),
					wc_price( $renewal_total, array( 'currency' => $subscription->get_currency() ) )
				)
			);
		}

		return true;
	}

	/**
	 * @param int                          $points Points.
	 * @param YITH_WC_Points_Rewards_Customer $customer Customer.
	 * @return float
	 */
	public function calculate_discount_for_points( $points, $customer ) {
		$worth = yith_points()->redeeming->calculate_price_worth_from_points( (int) $points, $customer, false );
		return max( 0, (float) $worth );
	}

	/**
	 * @param WC_Order        $renewal_order Renewal order.
	 * @param WC_Subscription $subscription Subscription.
	 * @return WC_Order
	 */
	public function apply_to_renewal_order( $renewal_order, $subscription ) {
		if ( ! $renewal_order instanceof WC_Order || ! $subscription instanceof WC_Subscription ) {
			return $renewal_order;
		}

		$this->strip_copied_subscription_load_meta( $renewal_order );

		$points = $this->get_loaded_points( $subscription );
		if ( $points <= 0 ) {
			return $renewal_order;
		}

		if ( 'yes' === $renewal_order->get_meta( self::ORDER_META_CONSUMED, true ) ) {
			return $renewal_order;
		}

		if ( $this->is_prepaid_fulfillment_renewal( $renewal_order ) ) {
			return $renewal_order;
		}

		$this->apply_discount_to_order( $renewal_order, $subscription, $points );
		return $renewal_order;
	}

	/**
	 * Subscription load meta must not be copied onto renewal orders.
	 *
	 * @param array $order_meta Meta keyed by meta_key => meta_value.
	 * @return array
	 */
	public function exclude_loaded_points_from_renewal_copy( $order_meta ) {
		unset(
			$order_meta[ self::SUB_META_POINTS ],
			$order_meta[ self::SUB_META_LOADED_AT ],
			$order_meta[ self::SUB_META_LOAD_TOKEN ]
		);

		return $order_meta;
	}

	/**
	 * @param WC_Cart $cart Cart.
	 */
	public function maybe_apply_to_renewal_cart( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( ! function_exists( 'wcs_cart_contains_renewal' ) ) {
			return;
		}

		$renewal_item = wcs_cart_contains_renewal();
		if ( ! $renewal_item || empty( $renewal_item['subscription_renewal']['subscription_id'] ) ) {
			return;
		}

		$subscription = wcs_get_subscription( (int) $renewal_item['subscription_renewal']['subscription_id'] );
		if ( ! $subscription ) {
			return;
		}

		$points = $this->get_loaded_points( $subscription );
		if ( $points <= 0 ) {
			return;
		}

		$customer = ywpar_get_customer( get_current_user_id() );
		if ( ! $customer ) {
			return;
		}

		$discount = $this->calculate_capped_discount( $points, $customer, $cart->get_subtotal() );
		if ( $discount <= 0 ) {
			return;
		}

		foreach ( $cart->get_fees() as $fee ) {
			if ( self::FEE_NAME === $fee->name ) {
				return;
			}
		}

		$cart->add_fee( self::FEE_NAME, -1 * $discount, false );
	}

	/**
	 * @param bool     $is_recurring Is recurring fee.
	 * @param stdClass $fee Fee.
	 * @param WC_Cart  $cart Cart.
	 * @return bool
	 */
	public function mark_points_fee_recurring( $is_recurring, $fee, $cart ) {
		if ( self::FEE_NAME === $fee->name ) {
			return true;
		}
		return $is_recurring;
	}

	/**
	 * @param int $order_id Order ID.
	 */
	public function maybe_finalize_redemption( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->is_paid() ) {
			return;
		}

		if ( $this->is_prepaid_fulfillment_renewal( $order ) ) {
			$this->strip_copied_subscription_load_meta( $order );
			return;
		}

		if ( ! $this->order_has_applied_points_discount( $order ) ) {
			$this->strip_copied_subscription_load_meta( $order );
			return;
		}

		$points = (int) $order->get_meta( self::ORDER_META_POINTS, true );
		if ( $points <= 0 ) {
			return;
		}

		if ( 'yes' === $order->get_meta( self::ORDER_META_CONSUMED, true ) ) {
			return;
		}

		$subscription_id = (int) $order->get_meta( self::ORDER_META_SUBSCRIPTION_ID, true );
		$subscription    = $subscription_id ? wcs_get_subscription( $subscription_id ) : null;

		if ( ! $subscription ) {
			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order );
			$subscription  = ! empty( $subscriptions ) ? reset( $subscriptions ) : null;
		}

		if ( ! $subscription ) {
			return;
		}

		if ( $this->get_loaded_points( $subscription ) !== $points ) {
			// Loaded amount changed after order was created; trust order meta.
		}

		$order->update_meta_data( self::ORDER_META_CONSUMED, 'yes' );
		$order->update_meta_data( '_ywpar_redemped_points', $points );
		$order->update_meta_data( '_ywpar_coupon_points', $points );
		$order->update_meta_data( '_ywpar_coupon_amount', (float) $order->get_meta( self::ORDER_META_DISCOUNT, true ) );
		$order->add_order_note(
			sprintf(
				/* translators: 1: points, 2: points label */
				__( 'Customer redeemed %1$d %2$s on subscription renewal.', 'freya-ywpar-subscriptions' ),
				$points,
				ywpar_get_option( 'points_label_plural', 'points' )
			)
		);
		$order->save();

		$this->clear_subscription_load( $subscription );
		$this->clear_discount_from_open_renewal_orders( $subscription, $order->get_id() );
	}

	/**
	 * @param WC_Subscription $subscription Subscription.
	 */
	public function release_on_subscription_end( $subscription ) {
		$subscription = $this->normalize_subscription( $subscription );
		if ( ! $subscription || $this->get_loaded_points( $subscription ) <= 0 ) {
			return;
		}

		$this->remove_loaded_points( (int) $subscription->get_user_id(), $subscription );
	}

	/**
	 * @param string $label Label.
	 * @param string $action Action key.
	 * @return string
	 */
	public function filter_action_labels( $label, $action ) {
		switch ( $action ) {
			case self::ACTION_RESERVED:
				return __( 'Points reserved for subscription renewal', 'freya-ywpar-subscriptions' );
			case self::ACTION_RELEASED:
				return __( 'Points released from subscription renewal', 'freya-ywpar-subscriptions' );
			case self::ACTION_REDEEMED:
				return __( 'Points redeemed on subscription renewal', 'freya-ywpar-subscriptions' );
		}
		return $label;
	}

	/**
	 * @param WC_Subscription $subscription Subscription.
	 */
	private function sync_open_renewal_orders( $subscription ) {
		foreach ( $this->get_unpaid_renewal_orders( $subscription ) as $order ) {
			$this->apply_discount_to_order( $order, $subscription, $this->get_loaded_points( $subscription ) );
		}
	}

	/**
	 * @param WC_Subscription $subscription Subscription.
	 * @param int             $except_order_id Order ID to skip.
	 */
	private function clear_discount_from_open_renewal_orders( $subscription, $except_order_id = 0 ) {
		foreach ( $this->get_unpaid_renewal_orders( $subscription ) as $order ) {
			if ( $except_order_id && (int) $order->get_id() === (int) $except_order_id ) {
				continue;
			}
			$this->remove_discount_from_order( $order );
		}
	}

	/**
	 * @param WC_Subscription $subscription Subscription.
	 * @return WC_Order[]
	 */
	private function get_unpaid_renewal_orders( $subscription ) {
		$orders = array();
		foreach ( $subscription->get_related_orders( 'all', 'renewal' ) as $order ) {
			if ( ! $order instanceof WC_Order ) {
				$order = wc_get_order( $order );
			}
			if ( ! $order || $order->is_paid() ) {
				continue;
			}
			if ( $order->has_status( array( 'pending', 'failed', 'on-hold' ) ) ) {
				$orders[] = $order;
			}
		}
		return $orders;
	}

	/**
	 * @param WC_Order        $order Order.
	 * @param WC_Subscription $subscription Subscription.
	 * @param int             $points Points.
	 */
	private function apply_discount_to_order( $order, $subscription, $points ) {
		if ( $this->is_prepaid_fulfillment_renewal( $order ) ) {
			return;
		}

		$customer = ywpar_get_customer( $subscription->get_user_id() );
		if ( ! $customer ) {
			return;
		}

		$this->remove_discount_from_order( $order );

		$order->calculate_totals();
		$cap_total = (float) $order->get_total();

		$discount = $this->calculate_capped_discount( $points, $customer, $cap_total );
		if ( $discount <= 0 ) {
			return;
		}

		$item = new WC_Order_Item_Fee();
		$item->set_name( self::FEE_NAME );
		$item->set_amount( -1 * $discount );
		$item->set_total( -1 * $discount );
		$item->set_tax_status( 'none' );
		$order->add_item( $item );

		$order->update_meta_data( self::ORDER_META_POINTS, $points );
		$order->update_meta_data( self::ORDER_META_DISCOUNT, $discount );
		$order->update_meta_data( self::ORDER_META_SUBSCRIPTION_ID, $subscription->get_id() );
		$order->calculate_totals();
		$order->save();
	}

	/**
	 * @param WC_Order $order Order.
	 */
	private function remove_discount_from_order( $order ) {
		$changed = false;
		foreach ( $order->get_fees() as $fee_id => $fee ) {
			if ( self::FEE_NAME === $fee->get_name() ) {
				$order->remove_item( $fee_id );
				$changed = true;
			}
		}

		if ( $changed || $order->get_meta( self::ORDER_META_POINTS, true ) ) {
			$order->delete_meta_data( self::ORDER_META_POINTS );
			$order->delete_meta_data( self::ORDER_META_DISCOUNT );
			$order->delete_meta_data( self::ORDER_META_SUBSCRIPTION_ID );
			$order->calculate_totals();
			$order->save();
		}
	}

	/**
	 * @param int                          $points Points.
	 * @param YITH_WC_Points_Rewards_Customer $customer Customer.
	 * @param float                        $cap_total Maximum discount.
	 * @return float
	 */
	private function calculate_capped_discount( $points, $customer, $cap_total ) {
		$discount = $this->calculate_discount_for_points( $points, $customer );
		if ( $cap_total > 0 ) {
			$discount = min( $discount, $cap_total );
		}
		return (float) wc_format_decimal( $discount );
	}

	/**
	 * @param WC_Subscription $subscription Subscription.
	 */
	private function clear_subscription_load( $subscription ) {
		$subscription->delete_meta_data( self::SUB_META_POINTS );
		$subscription->delete_meta_data( self::SUB_META_LOADED_AT );
		$subscription->delete_meta_data( self::SUB_META_LOAD_TOKEN );
		$subscription->save();
	}

	/**
	 * Prepaid subscriptions create $0 fulfillment renewals before the next paid cycle.
	 * Loaded points should stay on the subscription until that paid renewal.
	 *
	 * @param WC_Order $order Renewal order.
	 * @return bool
	 */
	private function is_prepaid_fulfillment_renewal( $order ) {
		return 'yes' === $order->get_meta( '_ps_prepaid_renewal', true );
	}

	/**
	 * True when this order actually received the points fee/discount meta from apply_discount_to_order().
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private function order_has_applied_points_discount( $order ) {
		if ( (float) $order->get_meta( self::ORDER_META_DISCOUNT, true ) > 0 ) {
			return true;
		}

		foreach ( $order->get_fees() as $fee ) {
			if ( self::FEE_NAME === $fee->get_name() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * WCS copies subscription meta onto renewals; remove load meta that was never applied as a discount.
	 *
	 * @param WC_Order $order Order.
	 */
	private function strip_copied_subscription_load_meta( $order ) {
		$changed = false;

		foreach ( array( self::SUB_META_POINTS, self::SUB_META_LOADED_AT, self::SUB_META_LOAD_TOKEN ) as $meta_key ) {
			if ( $order->meta_exists( $meta_key ) ) {
				$order->delete_meta_data( $meta_key );
				$changed = true;
			}
		}

		if ( $changed ) {
			$order->save();
		}
	}

	/**
	 * @param WC_Subscription|int $subscription Subscription or ID.
	 * @return WC_Subscription|null
	 */
	private function normalize_subscription( $subscription ) {
		if ( $subscription instanceof WC_Subscription ) {
			return $subscription;
		}
		if ( is_numeric( $subscription ) ) {
			$subscription = wcs_get_subscription( (int) $subscription );
			return $subscription instanceof WC_Subscription ? $subscription : null;
		}
		return null;
	}
}
