<?php
/**
 * Import / export settings tab.
 *
 * @package Freya_YWPAR_Subscriptions
 *
 * @var string $notice Notice key.
 * @var array  $counts Export counts.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="freya-ywpar-settings-portability yith-plugin-ui">
	<?php if ( 'imported' === $notice ) : ?>
		<div class="notice notice-success inline">
			<p>
				<?php
				printf(
					/* translators: 1: options count, 2: created posts, 3: updated posts, 4: deleted posts */
					esc_html__( 'Settings imported successfully. Updated %1$d options. Posts: %2$d created, %3$d updated, %4$d deleted.', 'freya-ywpar-subscriptions' ),
					isset( $_GET['options'] ) ? (int) $_GET['options'] : 0,
					isset( $_GET['created'] ) ? (int) $_GET['created'] : 0,
					isset( $_GET['updated'] ) ? (int) $_GET['updated'] : 0,
					isset( $_GET['deleted'] ) ? (int) $_GET['deleted'] : 0
				);
				?>
			</p>
		</div>
	<?php elseif ( 'invalid_json' === $notice || 'invalid_file' === $notice ) : ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'The uploaded file is not a valid YWPAR settings export.', 'freya-ywpar-subscriptions' ); ?></p></div>
	<?php elseif ( 'missing_file' === $notice ) : ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'Choose a JSON settings file to import.', 'freya-ywpar-subscriptions' ); ?></p></div>
	<?php endif; ?>

	<p class="description">
		<?php esc_html_e( 'Export or import YITH Points & Rewards configuration: panel options, earning/redeeming rules, banners, and levels. Customer point balances and history are not included.', 'freya-ywpar-subscriptions' ); ?>
	</p>

	<div class="freya-ywpar-settings-portability__grid">
		<div class="freya-ywpar-settings-portability__card">
			<h3><?php esc_html_e( 'Export settings', 'freya-ywpar-subscriptions' ); ?></h3>
			<p>
				<?php
				printf(
					/* translators: 1: options count, 2: posts count */
					esc_html__( 'Current site has %1$d options and %2$d configuration posts ready to export.', 'freya-ywpar-subscriptions' ),
					(int) $counts['options'],
					(int) $counts['posts']
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'freya_ywpar_export_settings' ); ?>
				<input type="hidden" name="action" value="freya_ywpar_export_settings" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Download JSON', 'freya-ywpar-subscriptions' ); ?></button>
			</form>
		</div>

		<div class="freya-ywpar-settings-portability__card">
			<h3><?php esc_html_e( 'Import settings', 'freya-ywpar-subscriptions' ); ?></h3>
			<p><?php esc_html_e( 'Upload a JSON file exported from another environment. Existing options with the same keys will be overwritten.', 'freya-ywpar-subscriptions' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'freya_ywpar_import_settings' ); ?>
				<input type="hidden" name="action" value="freya_ywpar_import_settings" />
				<p>
					<input type="file" name="freya_ywpar_settings_file" accept="application/json,.json" required />
				</p>
				<p>
					<label>
						<input type="checkbox" name="freya_ywpar_replace_posts" value="1" />
						<?php esc_html_e( 'Replace all earning rules, redeem rules, banners, and levels before import', 'freya-ywpar-subscriptions' ); ?>
					</label>
				</p>
				<p class="description">
					<?php esc_html_e( 'Leave unchecked to merge by slug (update matching items, create missing ones). Product IDs inside rules may still need review after importing to a different catalog.', 'freya-ywpar-subscriptions' ); ?>
				</p>
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Import JSON', 'freya-ywpar-subscriptions' ); ?></button>
			</form>
		</div>
	</div>

	<p class="description">
		<?php esc_html_e( 'CLI:', 'freya-ywpar-subscriptions' ); ?>
		<code>wp freya ywpar-settings export --file=yith-ywpar-settings.json</code>
		&nbsp;|&nbsp;
		<code>wp freya ywpar-settings import yith-ywpar-settings.json [--replace-posts]</code>
	</p>
</div>

<style>
	.freya-ywpar-settings-portability__grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
		gap: 20px;
		margin-top: 20px;
	}
	.freya-ywpar-settings-portability__card {
		background: #fff;
		border: 1px solid #dcdcde;
		border-radius: 4px;
		padding: 20px;
	}
	.freya-ywpar-settings-portability__card h3 {
		margin-top: 0;
	}
</style>
