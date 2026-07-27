<?php
/**
 * Referral link UI on the My Points account page.
 *
 * @package Freya_YWPAR_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * @return bool
 */
function freya_is_my_points_endpoint() {
	global $wp;

	if ( isset( $wp->query_vars['my-points'] ) ) {
		return true;
	}

	if ( ! function_exists( 'ywpar_get_option' ) ) {
		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'my-points' );
	}

	$endpoint = ywpar_get_option( 'my_account_page_endpoint', 'my-points' );
	return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( $endpoint ? $endpoint : 'my-points' );
}

/**
 * Inject referral share block into YITH my-points shortcode output.
 *
 * @param string $output Shortcode output.
 * @param string $tag    Shortcode tag.
 * @return string
 */
function freya_inject_my_account_referral_section( $output, $tag ) {
	if ( 'ywpar_my_account_points' !== $tag ) {
		return $output;
	}

	if ( false !== strpos( $output, 'freya-my-account-referral' ) ) {
		return $output;
	}

	$section = freya_get_my_account_referral_html();
	if ( '' === $section ) {
		return $output;
	}

	$replaced = preg_replace(
		'/(<div id="ywpar_tabs">)/',
		$section . '$1',
		$output,
		1,
		$count
	);

	if ( $count > 0 && is_string( $replaced ) ) {
		return $replaced;
	}

	return $output . $section;
}

/**
 * @return string
 */
function freya_get_my_account_referral_html() {
	if ( ! is_user_logged_in() || ! freya_is_my_points_endpoint() ) {
		return '';
	}

	$referral = freya_get_ywpar_referral_context( get_current_user_id() );
	if ( empty( $referral['show'] ) || empty( $referral['referral_link'] ) ) {
		return '';
	}

	ob_start();
	?>
	<section class="freya-my-account-referral" aria-labelledby="freya-my-account-referral-heading">
		<h3 id="freya-my-account-referral-heading">
			<?php
			echo wp_kses_post(
				! empty( $referral['referral_share_heading'] )
					? $referral['referral_share_heading']
					: __( 'Share Freya.<br>Earn toward every refill.', 'freya-ywpar-subscriptions' )
			);
			?>
		</h3>
		<p class="freya-my-account-referral__copy">
			<?php esc_html_e( 'Send your friends the link below. When they start their Freya journey, you earn points you can put straight toward your subscription. Redeem them whenever you like — one friend, ten friends, it\'s all yours.', 'freya-ywpar-subscriptions' ); ?>
		</p>

		<div class="freya-my-account-referral__linkbox">
			<input
				id="freya-my-account-ref-link"
				class="freya-my-account-referral__input"
				type="text"
				readonly
				value="<?php echo esc_attr( $referral['referral_link'] ); ?>"
				aria-label="<?php esc_attr_e( 'Your referral link', 'freya-ywpar-subscriptions' ); ?>"
			>
			<div class="freya-my-account-referral__copy-row">
				<button class="freya-my-account-referral__copy-btn" id="freya-my-account-copy-btn" type="button">
					<?php esc_html_e( 'Copy link', 'freya-ywpar-subscriptions' ); ?>
				</button>
				<span class="freya-my-account-referral__copied-msg" id="freya-my-account-copied-msg" role="status" aria-live="polite" hidden>
					<?php esc_html_e( 'Link copied!', 'freya-ywpar-subscriptions' ); ?>
				</span>
			</div>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}

/**
 * Enqueue referral assets on the my-points page.
 */
function freya_enqueue_my_account_referral_assets() {
	if ( ! is_account_page() || ! freya_is_my_points_endpoint() || ! is_user_logged_in() ) {
		return;
	}

	$referral = freya_get_ywpar_referral_context( get_current_user_id() );
	if ( empty( $referral['show'] ) || empty( $referral['referral_link'] ) ) {
		return;
	}

	wp_enqueue_style(
		'freya-my-account-referral',
		FREYA_YWPAR_SUB_URL . 'assets/css/my-account-referral.css',
		array(),
		FREYA_YWPAR_SUB_VERSION
	);

	wp_enqueue_script(
		'freya-my-account-referral',
		FREYA_YWPAR_SUB_URL . 'assets/js/my-account-referral.js',
		array(),
		FREYA_YWPAR_SUB_VERSION,
		true
	);

	wp_localize_script(
		'freya-my-account-referral',
		'freyaMyAccountReferral',
		array(
			'referralLink' => $referral['referral_link'],
			'shareMessage' => $referral['share_message'],
			'copyLabel'    => __( 'Copy link', 'freya-ywpar-subscriptions' ),
			'copiedLabel'  => __( 'Link copied!', 'freya-ywpar-subscriptions' ),
		)
	);
}

add_filter( 'do_shortcode_tag', 'freya_inject_my_account_referral_section', 15, 2 );
add_action( 'wp_enqueue_scripts', 'freya_enqueue_my_account_referral_assets' );
