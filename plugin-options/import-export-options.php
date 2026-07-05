<?php
/**
 * YITH panel tab registration for settings import/export.
 *
 * @package Freya_YWPAR_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

return array(
	'import-export' => array(
		'import-export-tab' => array(
			'type'           => 'custom_tab',
			'action'         => 'freya_ywpar_settings_import_export_tab',
			'show_container' => true,
			'title'          => esc_html__( 'Import / Export', 'freya-ywpar-subscriptions' ),
		),
	),
);
