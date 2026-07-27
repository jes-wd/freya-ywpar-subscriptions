<?php
/**
 * Share-points coupon delete and template overrides.
 *
 * @package Freya_YWPAR_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

class Freya_YWPAR_Share_Coupons {

	/** @var self */
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_locate_template', array( $this, 'locate_template' ), 20, 4 );
		add_action( 'wp_ajax_freya_ywpar_delete_shared_coupon', array( $this, 'ajax_delete_shared_coupon' ) );
	}

	/**
	 * Prefer plugin template for YITH share-points table.
	 *
	 * @param string $template      Resolved template path.
	 * @param string $template_name Template name.
	 * @param string $template_path Template path.
	 * @param string $default_path  Default path.
	 * @return string
	 */
	public function locate_template( $template, $template_name, $template_path, $default_path ) {
		unset( $template_path, $default_path );

		$normalized = ltrim( (string) $template_name, '/' );
		if ( 'myaccount/ywpar-share-points.php' !== $normalized ) {
			return $template;
		}

		$plugin_file = FREYA_YWPAR_SUB_PATH . 'templates/woocommerce/myaccount/ywpar-share-points.php';
		if ( file_exists( $plugin_file ) ) {
			return $plugin_file;
		}

		return $template;
	}

	/**
	 * Find a shared coupon entry regardless of code casing.
	 *
	 * @param array<string, mixed> $shared_coupons Shared coupons list.
	 * @param string               $code           Coupon code.
	 * @return array<string, mixed>|null
	 */
	private function get_shared_coupon_entry( $shared_coupons, $code ) {
		if ( isset( $shared_coupons[ $code ] ) ) {
			return $shared_coupons[ $code ];
		}

		$needle = strtolower( $code );
		foreach ( $shared_coupons as $stored_code => $entry ) {
			if ( strtolower( (string) $stored_code ) === $needle ) {
				return $entry;
			}
		}

		return null;
	}

	public function ajax_delete_shared_coupon() {
		check_ajax_referer( 'freya_ywpar_delete_shared_coupon', 'security' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error(
				array(
					'message' => __( 'You must be logged in.', 'freya-ywpar-subscriptions' ),
				),
				403
			);
		}

		$code = isset( $_POST['coupon'] ) ? wc_format_coupon_code( wp_unslash( $_POST['coupon'] ) ) : '';
		if ( '' === $code ) {
			wp_send_json_error(
				array(
					'message' => __( 'Coupon code is required.', 'freya-ywpar-subscriptions' ),
				),
				400
			);
		}

		$coupon = new WC_Coupon( $code );
		if ( ! $coupon->get_id() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Coupon not found.', 'freya-ywpar-subscriptions' ),
				),
				404
			);
		}

		$user_id = get_current_user_id();
		if ( (int) $coupon->get_meta( 'ywpar_shared_coupon_customer' ) !== $user_id ) {
			wp_send_json_error(
				array(
					'message' => __( 'You cannot delete this coupon.', 'freya-ywpar-subscriptions' ),
				),
				403
			);
		}

		if ( (int) $coupon->get_usage_count() > 0 ) {
			wp_send_json_error(
				array(
					'message' => __( 'Used coupons cannot be deleted.', 'freya-ywpar-subscriptions' ),
				),
				400
			);
		}

		$customer = ywpar_get_customer( $user_id );
		if ( ! $customer ) {
			wp_send_json_error(
				array(
					'message' => __( 'Customer not found.', 'freya-ywpar-subscriptions' ),
				),
				404
			);
		}

		$shared_coupons = $customer->get_shared_coupons();
		if ( null === $this->get_shared_coupon_entry( $shared_coupons, $code ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Coupon is not in your shared coupon list.', 'freya-ywpar-subscriptions' ),
				),
				404
			);
		}

		$points = (int) $coupon->get_meta( 'ywpar_shared_coupon_points' );
		if ( $points <= 0 ) {
			wp_send_json_error(
				array(
					'message' => __( 'Unable to determine points for this coupon.', 'freya-ywpar-subscriptions' ),
				),
				400
			);
		}

		$description = sprintf(
			/* translators: %s: coupon code */
			__( 'Restored points from deleted coupon: %s', 'freya-ywpar-subscriptions' ),
			$code
		);

		$customer->update_points(
			$points,
			'admin_action',
			array(
				'description' => $description,
			)
		);

		$coupon->delete( true );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: number of points */
					__( '%s points have been restored to your account.', 'freya-ywpar-subscriptions' ),
					number_format_i18n( $points )
				),
			)
		);
	}

	/**
	 * Whether a shared coupon row should show the delete action.
	 *
	 * @param WC_Coupon $coupon Coupon object.
	 * @return bool
	 */
	public static function can_delete_coupon( $coupon ) {
		if ( ! $coupon instanceof WC_Coupon || ! $coupon->get_id() ) {
			return false;
		}

		if ( (int) $coupon->get_usage_count() > 0 ) {
			return false;
		}

		if ( ! is_user_logged_in() ) {
			return false;
		}

		return (int) $coupon->get_meta( 'ywpar_shared_coupon_customer' ) === get_current_user_id();
	}
}
