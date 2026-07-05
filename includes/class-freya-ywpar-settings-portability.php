<?php
/**
 * Export and import YITH Points & Rewards plugin settings.
 *
 * @package Freya_YWPAR_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

class Freya_YWPAR_Settings_Portability {

	const SCHEMA_VERSION = '1.0';

	const EXPORT_FILENAME = 'yith-ywpar-settings.json';

	const POST_TYPES = array(
		'ywpar-earning-rule',
		'ywpar-redeeming-rule',
		'ywpar-banner',
		'ywpar-level-badge',
	);

	const EXCLUDED_OPTIONS = array(
		'ywpar_points_expired_check',
		'ywpar_3_0_0_import_status',
	);

	const SKIP_POST_META = array(
		'_edit_lock',
		'_edit_last',
	);

	/** @var self */
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'ywpar_admin_panel_options', array( $this, 'register_panel_tab' ) );
		add_filter( 'yith_plugin_panel_item_options_path', array( $this, 'panel_options_path' ), 10, 4 );
		add_action( 'freya_ywpar_settings_import_export_tab', array( $this, 'render_panel_tab' ) );

		add_action( 'admin_post_freya_ywpar_export_settings', array( $this, 'handle_export' ) );
		add_action( 'admin_post_freya_ywpar_import_settings', array( $this, 'handle_import' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			add_action( 'cli_init', array( $this, 'register_cli_commands' ) );
		}
	}

	/**
	 * @param array $args Panel args.
	 * @return array
	 */
	public function register_panel_tab( $args ) {
		if ( empty( $args['admin-tabs'] ) || ! is_array( $args['admin-tabs'] ) ) {
			return $args;
		}

		$args['admin-tabs']['import-export'] = array(
			'title'       => __( 'Import / Export', 'freya-ywpar-subscriptions' ),
			'icon'        => '<svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"></path></svg>',
			'description' => __( 'Back up or restore YITH Points & Rewards configuration (options and rules). Customer points are not included.', 'freya-ywpar-subscriptions' ),
		);

		return $args;
	}

	/**
	 * @param string           $path Options file path.
	 * @param string           $options_path Base options path.
	 * @param string           $item Tab key.
	 * @param YIT_Plugin_Panel $panel Panel instance.
	 * @return string
	 */
	public function panel_options_path( $path, $options_path, $item, $panel ) {
		unset( $options_path, $panel );

		if ( 'import-export' === $item ) {
			return FREYA_YWPAR_SUB_PATH . 'plugin-options/import-export-options.php';
		}

		return $path;
	}

	public function render_panel_tab() {
		if ( ! $this->current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'freya-ywpar-subscriptions' ) );
		}

		$notice = isset( $_GET['freya_ywpar_notice'] ) ? sanitize_key( wp_unslash( $_GET['freya_ywpar_notice'] ) ) : '';
		$counts = $this->get_export_counts();

		include FREYA_YWPAR_SUB_PATH . 'views/admin/import-export-tab.php';
	}

	/**
	 * @return bool
	 */
	public function current_user_can_manage() {
		$capability = function_exists( 'ywpar_get_manage_points_capability' )
			? ywpar_get_manage_points_capability()
			: 'manage_options';

		return current_user_can( $capability );
	}

	/**
	 * @return array<string, int>
	 */
	public function get_export_counts() {
		$counts = array(
			'options' => count( $this->get_options_for_export() ),
			'posts'   => 0,
		);

		foreach ( self::POST_TYPES as $post_type ) {
			$counts['posts'] += (int) wp_count_posts( $post_type )->publish
				+ (int) wp_count_posts( $post_type )->draft
				+ (int) wp_count_posts( $post_type )->private;
		}

		return $counts;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function build_export_payload() {
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'plugin'         => 'yith-woocommerce-points-and-rewards-premium',
			'exported_at'    => gmdate( 'c' ),
			'exported_from'  => home_url(),
			'options'        => $this->get_options_for_export(),
			'posts'          => $this->get_posts_for_export(),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function get_options_for_export() {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options}
			WHERE option_name LIKE 'ywpar\\_%' ESCAPE '\\\\'
				OR option_name LIKE 'yit\\_ywpar%' ESCAPE '\\\\'
			ORDER BY option_name ASC"
		);

		$options = array();
		foreach ( (array) $rows as $row ) {
			if ( in_array( $row->option_name, self::EXCLUDED_OPTIONS, true ) ) {
				continue;
			}
			$options[ $row->option_name ] = maybe_unserialize( $row->option_value );
		}

		return $options;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_posts_for_export() {
		$exported = array();

		foreach ( self::POST_TYPES as $post_type ) {
			$posts = get_posts(
				array(
					'post_type'              => $post_type,
					'post_status'            => array( 'publish', 'draft', 'private' ),
					'numberposts'            => -1,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'suppress_filters'       => true,
					'update_post_meta_cache' => true,
				)
			);

			foreach ( $posts as $post ) {
				$meta = get_post_meta( $post->ID );
				$clean_meta = array();

				foreach ( $meta as $meta_key => $values ) {
					if ( in_array( $meta_key, self::SKIP_POST_META, true ) ) {
						continue;
					}
					$clean_meta[ $meta_key ] = maybe_unserialize( $values[0] );
				}

				$exported[] = array(
					'post_type'    => $post->post_type,
					'post_title'   => $post->post_title,
					'post_name'    => $post->post_name,
					'post_status'  => $post->post_status,
					'post_content' => $post->post_content,
					'post_excerpt' => $post->post_excerpt,
					'menu_order'   => (int) $post->menu_order,
					'meta'         => $clean_meta,
				);
			}
		}

		return $exported;
	}

	/**
	 * @param array<string, mixed> $payload Export payload.
	 * @param array<string, mixed> $args Import args.
	 * @return array<string, int|string>
	 */
	public function import_payload( array $payload, array $args = array() ) {
		$replace_posts = ! empty( $args['replace_posts'] );

		if ( empty( $payload['schema_version'] ) || empty( $payload['options'] ) || ! is_array( $payload['options'] ) ) {
			return array(
				'error' => __( 'Invalid settings file.', 'freya-ywpar-subscriptions' ),
			);
		}

		$imported_options = 0;
		foreach ( $payload['options'] as $option_name => $value ) {
			if ( ! is_string( $option_name ) ) {
				continue;
			}
			if ( 0 !== strpos( $option_name, 'ywpar_' ) && 0 !== strpos( $option_name, 'yit_ywpar' ) ) {
				continue;
			}
			if ( in_array( $option_name, self::EXCLUDED_OPTIONS, true ) ) {
				continue;
			}

			update_option( $option_name, $value );
			++$imported_options;
		}

		$post_stats = array(
			'created' => 0,
			'updated' => 0,
			'deleted' => 0,
		);

		if ( $replace_posts ) {
			$post_stats['deleted'] = $this->delete_all_config_posts();
		}

		if ( ! empty( $payload['posts'] ) && is_array( $payload['posts'] ) ) {
			foreach ( $payload['posts'] as $post_data ) {
				if ( ! is_array( $post_data ) ) {
					continue;
				}
				$result = $this->import_post( $post_data, $replace_posts );
				if ( 'created' === $result ) {
					++$post_stats['created'];
				} elseif ( 'updated' === $result ) {
					++$post_stats['updated'];
				}
			}
		}

		return array(
			'imported_options' => $imported_options,
			'posts_created'    => $post_stats['created'],
			'posts_updated'    => $post_stats['updated'],
			'posts_deleted'    => $post_stats['deleted'],
		);
	}

	/**
	 * @return int Number of posts deleted.
	 */
	private function delete_all_config_posts() {
		$deleted = 0;

		foreach ( self::POST_TYPES as $post_type ) {
			$posts = get_posts(
				array(
					'post_type'        => $post_type,
					'post_status'      => 'any',
					'numberposts'      => -1,
					'fields'           => 'ids',
					'suppress_filters' => true,
				)
			);

			foreach ( $posts as $post_id ) {
				if ( wp_delete_post( (int) $post_id, true ) ) {
					++$deleted;
				}
			}
		}

		return $deleted;
	}

	/**
	 * @param array<string, mixed> $post_data Post export row.
	 * @param bool                 $replace_posts Whether posts were wiped first.
	 * @return string created|updated|skipped
	 */
	private function import_post( array $post_data, $replace_posts ) {
		$post_type = isset( $post_data['post_type'] ) ? sanitize_key( $post_data['post_type'] ) : '';
		if ( ! in_array( $post_type, self::POST_TYPES, true ) ) {
			return 'skipped';
		}

		$post_name = isset( $post_data['post_name'] ) ? sanitize_title( $post_data['post_name'] ) : '';
		$existing  = $post_name ? get_page_by_path( $post_name, OBJECT, $post_type ) : null;

		$postarr = array(
			'post_type'    => $post_type,
			'post_title'   => isset( $post_data['post_title'] ) ? wp_slash( (string) $post_data['post_title'] ) : '',
			'post_name'    => $post_name,
			'post_status'  => isset( $post_data['post_status'] ) ? sanitize_key( $post_data['post_status'] ) : 'publish',
			'post_content' => isset( $post_data['post_content'] ) ? wp_slash( (string) $post_data['post_content'] ) : '',
			'post_excerpt' => isset( $post_data['post_excerpt'] ) ? wp_slash( (string) $post_data['post_excerpt'] ) : '',
			'menu_order'   => isset( $post_data['menu_order'] ) ? (int) $post_data['menu_order'] : 0,
		);

		if ( $existing instanceof WP_Post && ! $replace_posts ) {
			$postarr['ID'] = $existing->ID;
			$post_id       = wp_update_post( $postarr, true );
			$action        = 'updated';
		} else {
			$post_id = wp_insert_post( $postarr, true );
			$action  = 'created';
		}

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 'skipped';
		}

		if ( ! empty( $post_data['meta'] ) && is_array( $post_data['meta'] ) ) {
			foreach ( $post_data['meta'] as $meta_key => $meta_value ) {
				if ( ! is_string( $meta_key ) || in_array( $meta_key, self::SKIP_POST_META, true ) ) {
					continue;
				}
				update_post_meta( (int) $post_id, $meta_key, $meta_value );
			}
		}

		return $action;
	}

	public function handle_export() {
		if ( ! $this->current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to export these settings.', 'freya-ywpar-subscriptions' ) );
		}

		check_admin_referer( 'freya_ywpar_export_settings' );

		$payload = $this->build_export_payload();
		$json    = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( ! is_string( $json ) ) {
			wp_die( esc_html__( 'Could not encode settings for export.', 'freya-ywpar-subscriptions' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . self::EXPORT_FILENAME . '"' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public function handle_import() {
		if ( ! $this->current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to import these settings.', 'freya-ywpar-subscriptions' ) );
		}

		check_admin_referer( 'freya_ywpar_import_settings' );

		$redirect = admin_url( 'admin.php?page=yith_woocommerce_points_and_rewards&tab=import-export' );

		if ( empty( $_FILES['freya_ywpar_settings_file']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'freya_ywpar_notice', 'missing_file', $redirect ) );
			exit;
		}

		$raw = file_get_contents( $_FILES['freya_ywpar_settings_file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$payload = json_decode( (string) $raw, true );

		if ( ! is_array( $payload ) ) {
			wp_safe_redirect( add_query_arg( 'freya_ywpar_notice', 'invalid_json', $redirect ) );
			exit;
		}

		$result = $this->import_payload(
			$payload,
			array(
				'replace_posts' => ! empty( $_POST['freya_ywpar_replace_posts'] ),
			)
		);

		if ( isset( $result['error'] ) ) {
			wp_safe_redirect( add_query_arg( 'freya_ywpar_notice', 'invalid_file', $redirect ) );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'freya_ywpar_notice' => 'imported',
					'options'            => (int) $result['imported_options'],
					'created'            => (int) $result['posts_created'],
					'updated'            => (int) $result['posts_updated'],
					'deleted'            => (int) $result['posts_deleted'],
				),
				$redirect
			)
		);
		exit;
	}

	/**
	 * Register WP-CLI commands.
	 */
	public function register_cli_commands() {
		WP_CLI::add_command(
			'freya ywpar-settings export',
			function( $args, $assoc_args ) {
				unset( $args );
				$file = isset( $assoc_args['file'] ) ? (string) $assoc_args['file'] : self::EXPORT_FILENAME;
				$payload = Freya_YWPAR_Settings_Portability::instance()->build_export_payload();
				$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

				if ( ! is_string( $json ) ) {
					WP_CLI::error( 'Could not encode settings.' );
				}

				if ( false === file_put_contents( $file, $json ) ) {
					WP_CLI::error( 'Could not write export file.' );
				}

				WP_CLI::success( 'Exported YWPAR settings to ' . $file );
			}
		);

		WP_CLI::add_command(
			'freya ywpar-settings import',
			function( $args, $assoc_args ) {
				if ( empty( $args[0] ) ) {
					WP_CLI::error( 'Usage: wp freya ywpar-settings import <file.json> [--replace-posts]' );
				}

				$raw = file_get_contents( $args[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$payload = json_decode( (string) $raw, true );

				if ( ! is_array( $payload ) ) {
					WP_CLI::error( 'Invalid JSON file.' );
				}

				$result = Freya_YWPAR_Settings_Portability::instance()->import_payload(
					$payload,
					array(
						'replace_posts' => ! empty( $assoc_args['replace-posts'] ),
					)
				);

				if ( isset( $result['error'] ) ) {
					WP_CLI::error( $result['error'] );
				}

				WP_CLI::success(
					sprintf(
						'Imported %d options. Posts: %d created, %d updated, %d deleted.',
						(int) $result['imported_options'],
						(int) $result['posts_created'],
						(int) $result['posts_updated'],
						(int) $result['posts_deleted']
					)
				);
			}
		);
	}
}
