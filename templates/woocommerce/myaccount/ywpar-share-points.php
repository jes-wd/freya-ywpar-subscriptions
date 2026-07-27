<?php
/**
 * Share points tab on My Account page (Freya override with delete action).
 *
 * @package Freya_YWPAR_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

$customer = ywpar_get_current_customer();
$points   = $customer->get_total_points();
$points   = empty( $points ) ? 0 : $points;
$plural   = ywpar_get_option( 'points_label_plural' );

?>
<div id="share_points" class="ywpar_tabcontent" role="tabpanel" tabindex="0" aria-labelledby="share_points-tab">

	<h3><?php
	// translators:Placeholder: label of points.
	echo esc_html( sprintf( _x( 'Convert %s into a coupon code', 'Placeholder: label of points;', 'yith-woocommerce-points-and-rewards' ), $plural ) ); ?></h3>
	<p><?php
	// translators:Placeholder: label of points.
	echo esc_html( sprintf( _x( 'Do you want to share your %s? Create a coupon code and share it so it can be used', 'Placeholder: label of points;', 'yith-woocommerce-points-and-rewards' ), $plural ) );
	?></p>

	<?php
	$share_usable_points = YITH_WC_Points_Rewards_Share_Points::get_max_points_usable( $customer->get_total_points() );
	$min_value_to_share  = YITH_WC_Points_Rewards_Share_Points::get_minimum_amount();
	$worth               = yith_points()->redeeming->calculate_price_worth_from_points( $share_usable_points, $customer );
	$points              = $customer->get_total_points();
	$shared_coupons      = array_filter( $customer->get_shared_coupons() );

	?>
	<?php
	if ( 0 === $min_value_to_share || 0 === $points ) :
		?>
		<span
			class="error show"><?php
			// translators:Placeholder: label of points.
			echo esc_html( sprintf( _x( 'At the moment you don\'t have enough %s to convert', 'Placeholder: label of points;', 'yith-woocommerce-points-and-rewards' ), $plural ) ); ?></span>
	<?php elseif ( $min_value_to_share > 0 && $points < $min_value_to_share ) : ?>
		<span class="error">
			<?php
			// translators:Placeholder is the minimum amount of points that can be used. Error message on My account share points tab.
			printf( esc_html_x( 'You need to convert a minimum of %s', 'Placeholder is the minimum amount of points that can be used. Error message on My account share points tab', 'yith-woocommerce-points-and-rewards' ), $min_value_to_share . ' ' . $plural );
			?>
		</span>
	<?php else : ?>

		<form id="ywpar-share-points" method="post">
			<?php wp_nonce_field( 'ywpar_share_points' ); ?>
			<input type="hidden" id="ywpar-share-points__min"
				value="<?php echo esc_attr( $min_value_to_share ); ?>">
			<input type="hidden" id="ywpar-share-points__customer"
				value="<?php echo esc_attr( $customer->get_id() ); ?>">
			<input type="hidden" id="ywpar-share-points__max"
				value="<?php echo esc_attr( $share_usable_points ); ?>">
			<span><?php echo esc_html_x( 'Convert', 'part of a sentence on My account page share points tab', 'yith-woocommerce-points-and-rewards' ); ?></span>
			<label class="screen-reader-text" for="ywpar_share_points_to_share">
				<?php
				// translators:Placeholder: label of points.
				echo esc_html( sprintf( _x( 'Enter the %s to share', 'Placeholder: label of points;', 'yith-woocommerce-points-and-rewards' ), $plural ) );
				?></label>
			<input type="number" step="1" min="<?php echo $min_value_to_share; ?>"
				max="<?php echo esc_attr( $share_usable_points ); ?>" id="ywpar_share_points_to_share"
				name="ywpar_share_points_to_share" value="<?php echo esc_attr( $share_usable_points ); ?>">
			<span><?php
			// translators: First placeholder: label of points; Second placeholder is worth price.
			printf( esc_html_x( '%s for a coupon value of %s', 'part of a sentence on My account page share points tab', 'yith-woocommerce-points-and-rewards' ), $plural, '<span class="worth-price">' . $worth . '</span>' ); ?></span>
			<input id="ywpar-share-points__submit" type="submit"
				value="<?php echo esc_html_x( 'Convert', 'Label of button to convert point on My account page share points tab', 'yith-woocommerce-points-and-rewards' ); ?>"/>
		</form>
		<p class="ywpar-share-points-error">
			<span class="error max-exceed">
				<?php
				// translators:Placeholder is the maximum amount of points that can be used. Error message on My account share points tab.
				printf( esc_html_x( 'You can convert a maximum of %s', 'Placeholder is the minimum amount of points that can be used. Error message on My account share points tab', 'yith-woocommerce-points-and-rewards' ), $share_usable_points . ' ' . $plural );
				?>
			</span>
			<span class="error min-exceed">
				<?php
				// translators:Placeholder is the minimum amount of points that can be used. Error message on My account share points tab.
				printf( esc_html_x( 'You need to convert a minimum of %s', 'Placeholder is the minimum amount of points that can be used. Error message on My account share points tab', 'yith-woocommerce-points-and-rewards' ), $min_value_to_share . ' ' . $plural );
				?>
			</span>
		</p>
	<?php endif; ?>
	<?php if ( ! empty( $shared_coupons ) ) : ?>
		<h3 class="your-coupons"><?php echo esc_html_x( 'Your coupons:', 'Title of table for the shared coupon in My Account page', 'yith-woocommerce-points-and-rewards' ); ?></h3>
		<table class="shop_table ywpar_points_rewards ywpar_share_points_table my_account_orders shop_table_responsive">
			<thead>
			<tr>
				<th class="ywpar-share-points-date-creation"><?php esc_html_e( 'Date of creation', 'yith-woocommerce-points-and-rewards' ); ?></th>
				<th class="ywpar-share-points-value"><?php esc_html_e( 'Value', 'yith-woocommerce-points-and-rewards' ); ?></th>
				<th class="ywpar-share-points-code"><?php esc_html_e( 'Code', 'yith-woocommerce-points-and-rewards' ); ?></th>
				<th class="ywpar-share-points-expire"><?php esc_html_e( 'Expire on', 'yith-woocommerce-points-and-rewards' ); ?></th>
				<th class="ywpar-share-points-status"><?php esc_html_e( 'Status', 'yith-woocommerce-points-and-rewards' ); ?></th>
				<th class="ywpar-share-points-actions"><?php esc_html_e( 'Actions', 'freya-ywpar-subscriptions' ); ?></th>
			</tr>
			</thead>
			<tbody>
			<?php
			$shared_coupons = array_reverse( $shared_coupons, true );

			foreach ( $shared_coupons as $code => $coupon ) :

				$coupon_info = YITH_WC_Points_Rewards_Share_Points::get_coupon_info( $coupon, $code );

				if ( isset( $coupon_info['date_created'], $coupon_info['amount'], $coupon_info['date_expires'], $coupon_info['status'] ) ) :
					$coupon_object = new WC_Coupon( $code );
					$can_delete    = class_exists( 'Freya_YWPAR_Share_Coupons' ) && Freya_YWPAR_Share_Coupons::can_delete_coupon( $coupon_object );
					?>
				<tr>
					<td class="ywpar-share-points-date-creation" data-title="<?php esc_attr_e( 'Date of creation', 'yith-woocommerce-points-and-rewards' ); ?>"><?php echo esc_html( $coupon_info['date_created'] ); ?></td>
					<td class="ywpar-share-points-value" data-title="<?php esc_html_e( 'Value', 'yith-woocommerce-points-and-rewards' ); ?>"><?php echo wp_kses_post( $coupon_info['amount'] ); ?></td>
					<td class="ywpar-share-points-code" data-title="<?php esc_html_e( 'Code', 'yith-woocommerce-points-and-rewards' ); ?>"><?php echo YITH_WC_Points_Rewards_Share_Points::print_coupon_code_field( $code ); ?></td>
					<td class="ywpar-share-points-expire" data-title="<?php esc_html_e( 'Expire on', 'yith-woocommerce-points-and-rewards' ); ?>"><?php echo esc_html( $coupon_info['date_expires'] ); ?></td>
					<td class="ywpar-share-points-status" data-title="<?php esc_html_e( 'Status', 'yith-woocommerce-points-and-rewards' ); ?>"><?php echo wp_kses_post( $coupon_info['status'] ); ?></td>
					<td class="ywpar-share-points-actions" data-title="<?php esc_attr_e( 'Actions', 'freya-ywpar-subscriptions' ); ?>">
						<?php if ( $can_delete ) : ?>
							<button type="button" class="freya-ywpar-delete-shared-coupon" data-coupon="<?php echo esc_attr( $code ); ?>">
								<?php esc_html_e( 'Delete', 'freya-ywpar-subscriptions' ); ?>
							</button>
						<?php else : ?>
							<span class="freya-ywpar-delete-shared-coupon__empty" aria-hidden="true">—</span>
						<?php endif; ?>
					</td>
				</tr>
					<?php
				endif;
			endforeach;
			?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
