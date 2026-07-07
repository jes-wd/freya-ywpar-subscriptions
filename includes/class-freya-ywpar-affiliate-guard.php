<?php
/**
 * Exclude YITH WooCommerce Affiliates from the points program.
 *
 * Affiliates often keep the customer/subscriber role alongside yith_affiliate,
 * so YITH's role allow-list cannot exclude them on its own.
 *
 * @package Freya_YWPAR_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

class Freya_YWPAR_Affiliate_Guard {

	/** @var self */
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'ywpar_is_user_enabled', array( $this, 'disable_points_for_affiliates' ), 20, 3 );
		add_filter( 'ywpar_prevent_extra_points', array( $this, 'prevent_extra_points_for_affiliates' ), 10, 4 );
		add_filter( 'ywpar_add_order_points', array( $this, 'skip_order_points_for_affiliates' ), 10, 2 );
		add_filter( 'ywpar_add_affiliate_commission_points', array( $this, 'block_affiliate_commission_points' ), 10, 4 );
		add_filter( 'ywpar_commission_points_for_affiliate', array( $this, 'zero_affiliate_commission_points' ), 10, 3 );
		add_filter( 'ywpar_customer_can_share_points', array( $this, 'disable_share_points_for_affiliates' ), 10, 2 );
		add_filter( 'ywpar_customers_table_list_args', array( $this, 'exclude_affiliates_from_customer_list' ) );
		add_action( 'pre_get_users', array( $this, 'exclude_affiliates_from_points_user_queries' ) );
		add_action( 'wp_ajax_ywpar_update_points', array( $this, 'block_admin_point_awards_to_affiliates' ), 5 );
	}

	/**
	 * @return string
	 */
	public static function get_affiliate_role() {
		if ( class_exists( 'YITH_WCAF_Affiliates' ) && is_callable( array( 'YITH_WCAF_Affiliates', 'get_role' ) ) ) {
			return (string) YITH_WCAF_Affiliates::get_role();
		}

		return 'yith_affiliate';
	}

	/**
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function user_has_affiliate_role( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->roles ) ) {
			return false;
		}

		return in_array( self::get_affiliate_role(), (array) $user->roles, true );
	}

	/**
	 * @param bool                            $is_enabled Current status.
	 * @param string                          $action     earn|redeem.
	 * @param YITH_WC_Points_Rewards_Customer $customer   Customer.
	 * @return bool
	 */
	public function disable_points_for_affiliates( $is_enabled, $action, $customer ) {
		unset( $action );

		if ( ! $is_enabled || ! $customer instanceof YITH_WC_Points_Rewards_Customer ) {
			return $is_enabled;
		}

		return self::user_has_affiliate_role( $customer->get_id() ) ? false : $is_enabled;
	}

	/**
	 * @param bool   $prevent  Current value.
	 * @param array  $types    Extra point types.
	 * @param int    $user_id  User ID.
	 * @param int    $order_id Order ID.
	 * @return bool
	 */
	public function prevent_extra_points_for_affiliates( $prevent, $types, $user_id, $order_id ) {
		unset( $types, $order_id );

		return $prevent || self::user_has_affiliate_role( $user_id );
	}

	/**
	 * @param bool $skip     Whether to skip awarding order points.
	 * @param int  $order_id Order ID.
	 * @return bool
	 */
	public function skip_order_points_for_affiliates( $skip, $order_id ) {
		if ( $skip ) {
			return $skip;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $skip;
		}

		return self::user_has_affiliate_role( $order->get_user_id() );
	}

	/**
	 * @param bool   $allow      Whether to award commission points.
	 * @param array  $affiliate  Affiliate data.
	 * @param WC_Order $order    Order.
	 * @param array  $commission Commission data.
	 * @return bool
	 */
	public function block_affiliate_commission_points( $allow, $affiliate, $order, $commission ) {
		unset( $order, $commission );

		$user_id = is_array( $affiliate ) && ! empty( $affiliate['user_id'] ) ? (int) $affiliate['user_id'] : 0;

		return $allow && ! self::user_has_affiliate_role( $user_id );
	}

	/**
	 * @param int    $points   Commission points.
	 * @param int    $order_id Order ID.
	 * @param string $token    Affiliate token.
	 * @return int
	 */
	public function zero_affiliate_commission_points( $points, $order_id, $token ) {
		unset( $order_id, $token );

		return $points > 0 ? 0 : $points;
	}

	/**
	 * @param bool                            $is_enabled Current status.
	 * @param YITH_WC_Points_Rewards_Customer $customer   Customer.
	 * @return bool
	 */
	public function disable_share_points_for_affiliates( $is_enabled, $customer ) {
		if ( ! $is_enabled || ! $customer instanceof YITH_WC_Points_Rewards_Customer ) {
			return $is_enabled;
		}

		return self::user_has_affiliate_role( $customer->get_id() ) ? false : $is_enabled;
	}

	/**
	 * @param array $args WP_User_Query arguments.
	 * @return array
	 */
	public function exclude_affiliates_from_customer_list( $args ) {
		return $this->append_affiliate_role_exclusion( $args );
	}

	/**
	 * @param WP_User_Query $query User query.
	 */
	public function exclude_affiliates_from_points_user_queries( $query ) {
		if ( ! $query instanceof WP_User_Query || ! $this->is_ywpar_user_query_context() ) {
			return;
		}

		$query->query_vars = $this->append_affiliate_role_exclusion( $query->query_vars );
	}

	/**
	 * @param array $args Query arguments.
	 * @return array
	 */
	private function append_affiliate_role_exclusion( $args ) {
		$role = self::get_affiliate_role();

		if ( ! isset( $args['role__not_in'] ) || ! is_array( $args['role__not_in'] ) ) {
			$args['role__not_in'] = array();
		}

		if ( ! in_array( $role, $args['role__not_in'], true ) ) {
			$args['role__not_in'][] = $role;
		}

		return $args;
	}

	/**
	 * @return bool
	 */
	private function is_ywpar_user_query_context() {
		if ( is_admin() && isset( $_GET['page'] ) && 'yith_woocommerce_points_and_rewards' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		if ( wp_doing_ajax() && isset( $_POST['action'] ) && 'ywpar_bulk_action' === $_POST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return true;
		}

		return false;
	}

	/**
	 * Stop manual admin point awards to affiliates from the customer history screen.
	 */
	public function block_admin_point_awards_to_affiliates() {
		if ( ! isset( $_REQUEST['user_id'], $_REQUEST['action_type'], $_REQUEST['points_amount'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$action = sanitize_key( wp_unslash( $_REQUEST['action_type'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'remove' === $action ) {
			return;
		}

		$user_id = (int) sanitize_text_field( wp_unslash( $_REQUEST['user_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! self::user_has_affiliate_role( $user_id ) ) {
			return;
		}

		wp_send_json(
			array(
				'error' => esc_html__( 'Affiliate users cannot receive points.', 'freya-ywpar-subscriptions' ),
			)
		);
	}
}
