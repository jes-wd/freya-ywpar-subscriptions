<?php
/**
 * Load points onto the next subscription renewal.
 *
 * @var YITH_WC_Points_Rewards_Customer $customer
 * @var Freya_YWPAR_Renewal_Manager      $manager
 * @var array                            $subscriptions
 * @var int                              $available_points
 * @var string                           $notice
 * @var string                           $notice_text
 * @var string                           $points_label
 * @var bool                             $in_manage_tab
 */

defined( 'ABSPATH' ) || exit;

$in_manage_tab   = ! empty( $in_manage_tab );
$section_classes = 'freya-ywpar-renewal-points';

if ( $in_manage_tab ) {
	$section_classes .= ' freya-ywpar-renewal-points--in-tab';
}

$first = ! empty( $subscriptions ) ? $subscriptions[0] : null;
$any_loaded = ! empty( array_filter( array_column( $subscriptions, 'loaded_points' ) ) );
$points_worth = ( $available_points > 0 && $customer )
	? $manager->calculate_discount_for_points( $available_points, $customer )
	: 0;
?>

<section class="<?php echo esc_attr( $section_classes ); ?>" aria-labelledby="freya-ywpar-renewal-points-title">
	<h3 id="freya-ywpar-renewal-points-title"><?php esc_html_e( 'Use points on your next renewal', 'freya-ywpar-subscriptions' ); ?></h3>

	<p class="freya-ywpar-renewal-points__intro">
		<?php esc_html_e( 'Reserve Points now and they will be applied as a discount when your subscription renews. If payment fails, the same reserved points stay attached until you pay that renewal or remove them.', 'freya-ywpar-subscriptions' ); ?>
	</p>

	<?php if ( $notice && $notice_text ) : ?>
		<div class="woocommerce-message freya-ywpar-renewal-points__notice freya-ywpar-renewal-points__notice--<?php echo esc_attr( $notice ); ?>" role="alert">
			<?php echo esc_html( $notice_text ); ?>
		</div>
	<?php endif; ?>

	<?php if ( $first ) : ?>
		<div class="freya-ywpar-renewal-points__panel">
			<div class="freya-ywpar-renewal-points__field">
				<label for="freya-ywpar-subscription"><?php esc_html_e( 'Apply points to subscription', 'freya-ywpar-subscriptions' ); ?></label>
				<select id="freya-ywpar-subscription" name="subscription_id">
					<?php foreach ( $subscriptions as $i => $sub ) : ?>
						<option
							value="<?php echo esc_attr( $sub['id'] ); ?>"
							data-status="<?php echo esc_attr( $sub['status_label'] ); ?>"
							data-total="<?php echo esc_attr( $sub['renewal_total'] ); ?>"
							data-next="<?php echo esc_attr( $sub['next_payment'] ); ?>"
							data-loaded="<?php echo esc_attr( $sub['loaded_points'] ); ?>"
							data-loaded-text="<?php echo esc_attr( $sub['loaded_text'] ); ?>"
							<?php selected( $i, 0 ); ?>
						>
							<?php echo esc_html( $sub['title'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="freya-ywpar-renewal-points__summary" id="freya-ywpar-summary">
				<span class="freya-ywpar-renewal-points__status"><?php echo esc_html( $first['status_label'] ); ?></span>
				<span><strong><?php esc_html_e( 'Renewal total:', 'freya-ywpar-subscriptions' ); ?></strong> <?php echo esc_html( $first['renewal_total'] ); ?></span>
				<span><strong><?php esc_html_e( 'Next payment:', 'freya-ywpar-subscriptions' ); ?></strong> <?php echo esc_html( $first['next_payment'] ); ?></span>
			</div>

			<div class="freya-ywpar-renewal-points__loaded" id="freya-ywpar-loaded" hidden>
				<p id="freya-ywpar-loaded-text"></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="freya-ywpar-renewal-points__form freya-ywpar-renewal-points__form--remove">
					<?php wp_nonce_field( 'freya_ywpar_renewal_points', 'freya_ywpar_nonce' ); ?>
					<input type="hidden" name="action" value="freya_ywpar_remove_renewal_points">
					<input type="hidden" name="subscription_id" id="freya-ywpar-remove-sub-id" value="<?php echo esc_attr( $first['id'] ); ?>">
					<button type="submit" class="button freya-ywpar-renewal-points__remove">
						<?php esc_html_e( 'Remove from next renewal', 'freya-ywpar-subscriptions' ); ?>
					</button>
				</form>
			</div>

			<?php if ( $available_points > 0 ) : ?>
				<form class="freya-ywpar-renewal-points__form freya-ywpar-renewal-points__form--load" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="freya-ywpar-load-fields">
					<?php wp_nonce_field( 'freya_ywpar_load_points', 'freya_ywpar_nonce' ); ?>
					<input type="hidden" name="action" value="freya_ywpar_load_points">
					<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ); ?>">
					<input type="hidden" name="subscription_id" id="freya-ywpar-load-sub-id" value="<?php echo esc_attr( $first['id'] ); ?>">

					<div class="freya-ywpar-renewal-points__field">
						<label for="freya-ywpar-points"><?php esc_html_e( 'Points to load', 'freya-ywpar-subscriptions' ); ?></label>
						<input
							type="number"
							id="freya-ywpar-points"
							name="points"
							min="1"
							max="<?php echo esc_attr( $available_points ); ?>"
							step="1"
							value="<?php echo esc_attr( $available_points ); ?>"
							required
						>
						<p class="freya-ywpar-renewal-points__worth">
							<?php esc_html_e( 'Points for a value of', 'freya-ywpar-subscriptions' ); ?>
							<span class="freya-ywpar-renewal-points__worth-price" id="freya-ywpar-worth-price"><?php echo wp_kses_post( wc_price( $points_worth ) ); ?></span>
						</p>
					</div>
					<button type="submit" class="button alt"><?php esc_html_e( 'Load onto next renewal', 'freya-ywpar-subscriptions' ); ?></button>
				</form>
			<?php elseif ( ! $any_loaded ) : ?>
				<p class="freya-ywpar-renewal-points__empty">
					<?php esc_html_e( 'You have no points available to load right now.', 'freya-ywpar-subscriptions' ); ?>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</section>
