<?php
/**
 * My Account UI for loading points onto subscription renewals.
 *
 * @package Freya_YWPAR_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

class Freya_YWPAR_My_Account {

	/** @var self */
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'do_shortcode_tag', array( $this, 'inject_into_my_points_shortcode' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_freya_ywpar_load_points', array( $this, 'handle_load' ) );
		add_action( 'admin_post_freya_ywpar_load_renewal_points', array( $this, 'handle_load' ) );
		add_action( 'admin_post_freya_ywpar_remove_renewal_points', array( $this, 'handle_remove' ) );
	}

	public function enqueue_assets() {
		if ( ! is_account_page() || ! $this->is_my_points_endpoint() ) {
			return;
		}

		if ( class_exists( 'YITH_WC_Points_Rewards_Share_Points' ) && YITH_WC_Points_Rewards_Share_Points::is_enabled() ) {
			wp_enqueue_style(
				'freya-ywpar-share-coupons',
				FREYA_YWPAR_SUB_URL . 'assets/css/share-coupons.css',
				array(),
				FREYA_YWPAR_SUB_VERSION
			);
		}

		wp_enqueue_style(
			'freya-ywpar-renewal-points',
			FREYA_YWPAR_SUB_URL . 'assets/css/renewal-points.css',
			array(),
			FREYA_YWPAR_SUB_VERSION
		);

		wp_enqueue_script(
			'freya-ywpar-renewal-points',
			FREYA_YWPAR_SUB_URL . 'assets/js/renewal-points.js',
			array(),
			FREYA_YWPAR_SUB_VERSION,
			true
		);

		wp_localize_script(
			'freya-ywpar-renewal-points',
			'freyaYwparRenewal',
			array(
				'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
				'customerId'           => (string) get_current_user_id(),
				'shareNonce'           => wp_create_nonce( 'ywpar_share_points' ),
				'deleteCouponNonce'    => wp_create_nonce( 'freya_ywpar_delete_shared_coupon' ),
				'deleteCouponConfirm'  => __( 'Delete this coupon and restore the points to your account?', 'freya-ywpar-subscriptions' ),
				'deleteCouponDeleting' => __( 'Deleting…', 'freya-ywpar-subscriptions' ),
				'deleteCouponError'    => __( 'Could not delete this coupon. Please try again.', 'freya-ywpar-subscriptions' ),
			)
		);
	}

	/**
	 * Append renewal UI to YITH my-points shortcode output.
	 *
	 * @param string $output Shortcode output.
	 * @param string $tag    Shortcode tag.
	 * @return string
	 */
	public function inject_into_my_points_shortcode( $output, $tag ) {
		if ( 'ywpar_my_account_points' !== $tag ) {
			return $output;
		}

		if ( false !== strpos( $output, 'freya-ywpar-renewal-points' ) ) {
			return $output;
		}

		$in_manage_tab = false !== strpos( $output, 'id="share_points"' );
		$section       = $this->get_section_html( $in_manage_tab );
		if ( '' === $section ) {
			return $output;
		}

		if ( $in_manage_tab ) {
			$replaced = preg_replace(
				'/(<div id="share_points"[^>]*>)(.*)(<\/div>\s*<\/div>\s*<\/div>\s*)$/s',
				'$1$2' . $section . '$3',
				$output,
				1,
				$count
			);

			if ( $count > 0 && is_string( $replaced ) ) {
				return $replaced;
			}
		}

		return $output . $this->get_section_html( false );
	}

	/**
	 * @param bool $in_manage_tab Whether the section renders inside YITH's Manage Points tab.
	 * @return string
	 */
	private function get_section_html( $in_manage_tab = false ) {
		if ( ! is_user_logged_in() || 'yes' !== ywpar_get_option( 'enable_rewards_points' ) ) {
			return '';
		}

		if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return '';
		}

		$customer = ywpar_get_current_customer();
		if ( ! $customer || ! $customer->is_enabled( 'redeem' ) ) {
			return '';
		}

		$manager          = Freya_YWPAR_Renewal_Manager::instance();
		$user_id          = get_current_user_id();
		$eligible         = $manager->get_eligible_subscriptions( $user_id );
		$notice           = isset( $_GET['freya_ywpar_notice'] ) ? sanitize_key( wp_unslash( $_GET['freya_ywpar_notice'] ) ) : '';
		$notice_text      = isset( $_GET['freya_ywpar_message'] ) ? sanitize_text_field( wp_unslash( urldecode( (string) $_GET['freya_ywpar_message'] ) ) ) : '';
		$points_label     = ywpar_get_option( 'points_label_plural', 'points' );
		$available_points = $customer ? (int) $customer->get_total_points() : 0;
		$subscriptions    = $this->build_subscriptions_for_template( $eligible, $manager, $customer, $points_label );

		if ( empty( $subscriptions ) ) {
			return '';
		}

		ob_start();
		wc_get_template(
			'myaccount/renewal-points-loader.php',
			array(
				'customer'         => $customer,
				'manager'          => $manager,
				'subscriptions'    => $subscriptions,
				'available_points' => $available_points,
				'notice'           => $notice,
				'notice_text'      => $notice_text,
				'points_label'     => $points_label,
				'in_manage_tab'    => $in_manage_tab,
			),
			'',
			FREYA_YWPAR_SUB_PATH . 'templates/'
		);
		return (string) ob_get_clean();
	}

	/**
	 * @param WC_Subscription[]                $eligible Eligible subscriptions.
	 * @param Freya_YWPAR_Renewal_Manager      $manager Renewal manager.
	 * @param YITH_WC_Points_Rewards_Customer  $customer Customer.
	 * @param string                           $points_label Points label.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_subscriptions_for_template( $eligible, $manager, $customer, $points_label ) {
		$subscriptions = array();

		foreach ( $eligible as $subscription ) {
			$subscription_id = $subscription->get_id();
			$product_names     = array();

			foreach ( $subscription->get_items() as $item ) {
				$product_names[] = $item->get_name();
			}

			$title        = ! empty( $product_names ) ? implode( ', ', $product_names ) : sprintf( __( 'Subscription #%d', 'freya-ywpar-subscriptions' ), $subscription_id );
			$next_payment = $subscription->get_date( 'next_payment' );
			$loaded_points = $manager->get_loaded_points( $subscription );
			$loaded_discount = $loaded_points && $customer ? $manager->calculate_discount_for_points( $loaded_points, $customer ) : 0;
			$loaded_text = '';

			if ( $loaded_points > 0 ) {
				$loaded_text = sprintf(
					/* translators: 1: points number, 2: points label, 3: discount amount */
					__( 'Loaded for next renewal: %1$s %2$s (%3$s discount)', 'freya-ywpar-subscriptions' ),
					number_format_i18n( $loaded_points ),
					$points_label,
					wp_strip_all_tags( wc_price( $loaded_discount, array( 'currency' => $subscription->get_currency() ) ) )
				);
			}

			$subscriptions[] = array(
				'id'            => $subscription_id,
				'title'         => $title,
				'status_label'  => wcs_get_subscription_status_name( $subscription->get_status() ),
				'renewal_total' => wp_strip_all_tags( $subscription->get_formatted_order_total() ),
				'next_payment'  => $next_payment ? date_i18n( wc_date_format(), strtotime( $next_payment ) ) : '—',
				'loaded_points' => $loaded_points,
				'loaded_text'   => $loaded_text,
			);
		}

		return $subscriptions;
	}

	/**
	 * @return bool
	 */
	private function is_my_points_endpoint() {
		global $wp;

		if ( isset( $wp->query_vars['my-points'] ) ) {
			return true;
		}

		if ( ! function_exists( 'ywpar_get_option' ) ) {
			return is_wc_endpoint_url( 'my-points' );
		}

		$endpoint = ywpar_get_option( 'my_account_page_endpoint', 'my-points' );
		return is_wc_endpoint_url( $endpoint ? $endpoint : 'my-points' );
	}

	/**
	 * @return string
	 */
	private function get_my_points_endpoint_url() {
		if ( ! function_exists( 'ywpar_get_option' ) ) {
			return wc_get_account_endpoint_url( 'my-points' );
		}

		$endpoint = ywpar_get_option( 'my_account_page_endpoint', 'my-points' );
		return wc_get_account_endpoint_url( $endpoint ? $endpoint : 'my-points' );
	}

	public function handle_load() {
		$this->handle_action( 'load' );
	}

	public function handle_remove() {
		$this->handle_action( 'remove' );
	}

	/**
	 * @param string $type load|remove.
	 */
	private function handle_action( $type ) {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
			exit;
		}

		$redirect = $this->get_my_points_endpoint_url();
		$nonce    = isset( $_POST['freya_ywpar_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['freya_ywpar_nonce'] ) ) : '';

		if ( 'remove' === $type ) {
			if ( ! wp_verify_nonce( $nonce, 'freya_ywpar_renewal_points' ) ) {
				$this->redirect_with_notice( $redirect, 'error', __( 'Security check failed. Please try again.', 'freya-ywpar-subscriptions' ) );
			}
		} elseif ( ! wp_verify_nonce( $nonce, 'freya_ywpar_load_points' ) && ! wp_verify_nonce( $nonce, 'freya_ywpar_renewal_points' ) ) {
			$this->redirect_with_notice( $redirect, 'error', __( 'Security check failed. Please try again.', 'freya-ywpar-subscriptions' ) );
		}

		$subscription_id = isset( $_POST['subscription_id'] ) ? absint( $_POST['subscription_id'] ) : 0;
		$manager         = Freya_YWPAR_Renewal_Manager::instance();
		$user_id         = get_current_user_id();

		if ( 'remove' === $type ) {
			$result = $manager->remove_loaded_points( $user_id, $subscription_id );
		} else {
			$points = isset( $_POST['points'] ) ? absint( $_POST['points'] ) : 0;
			$result = $manager->load_points( $user_id, $subscription_id, $points );
		}

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( $redirect, 'error', $result->get_error_message() );
		}

		if ( 'remove' === $type ) {
			$this->redirect_with_notice( $redirect, 'success', __( 'Points removed from your next renewal.', 'freya-ywpar-subscriptions' ) );
		}

		$this->redirect_with_notice( $redirect, 'success', __( 'Points loaded onto your next renewal.', 'freya-ywpar-subscriptions' ) );
	}

	/**
	 * @param string $url Redirect URL.
	 * @param string $type notice type.
	 * @param string $message Message.
	 */
	private function redirect_with_notice( $url, $type, $message ) {
		$url = add_query_arg(
			array(
				'freya_ywpar_notice'  => $type,
				'freya_ywpar_message' => rawurlencode( $message ),
			),
			$url
		);
		wp_safe_redirect( $url );
		exit;
	}
}
