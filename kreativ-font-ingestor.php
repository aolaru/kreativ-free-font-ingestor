<?php
/**
 * Plugin Name: Kreativ Font Ingestor
 * Plugin URI: https://example.com/kreativ-font-ingestor
 * Description: Imports open-source Google Fonts, stores them locally with OFL licensing, generates ZIP packages, and publishes SEO-ready WordPress posts.
 * Version: 1.0.5
 * Author: Kreativ
 * Author URI: https://example.com
 * Text Domain: kreativ-font-ingestor
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package KreativFontIngestor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KFI_VERSION', '1.0.5' );
define( 'KFI_PLUGIN_FILE', __FILE__ );
define( 'KFI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KFI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'KFI_OPTION_SETTINGS', 'kfi_settings' );
define( 'KFI_TRANSIENT_FONTS', 'kfi_google_fonts_list' );
define( 'KFI_CRON_HOOK', 'kfi_cron_import_fonts' );
define( 'KFI_IMPORT_LOCK', 'kfi_import_lock' );
define( 'KFI_TABLE_IMPORTS', 'kfi_imported_fonts' );

require_once KFI_PLUGIN_DIR . 'includes/class-logger.php';
require_once KFI_PLUGIN_DIR . 'includes/class-api.php';
require_once KFI_PLUGIN_DIR . 'includes/class-downloader.php';
require_once KFI_PLUGIN_DIR . 'includes/class-zipper.php';
require_once KFI_PLUGIN_DIR . 'includes/class-publisher.php';
require_once KFI_PLUGIN_DIR . 'includes/class-cron.php';
require_once KFI_PLUGIN_DIR . 'includes/class-featured-image.php';
require_once KFI_PLUGIN_DIR . 'includes/class-frontend.php';
require_once KFI_PLUGIN_DIR . 'admin/class-admin-ui.php';

final class KFI_Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var KFI_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Logger instance.
	 *
	 * @var KFI_Logger
	 */
	private $logger;

	/**
	 * API client.
	 *
	 * @var KFI_API
	 */
	private $api;

	/**
	 * Downloader.
	 *
	 * @var KFI_Downloader
	 */
	private $downloader;

	/**
	 * Zipper.
	 *
	 * @var KFI_Zipper
	 */
	private $zipper;

	/**
	 * Publisher.
	 *
	 * @var KFI_Publisher
	 */
	private $publisher;

	/**
	 * Cron controller.
	 *
	 * @var KFI_Cron
	 */
	private $cron;

	/**
	 * Featured image generator.
	 *
	 * @var KFI_Featured_Image
	 */
	private $featured_image;

	/**
	 * Frontend controller.
	 *
	 * @var KFI_Frontend
	 */
	private $frontend;

	/**
	 * Admin controller.
	 *
	 * @var KFI_Admin_UI
	 */
	private $admin;

	/**
	 * Get singleton instance.
	 *
	 * @return KFI_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger     = new KFI_Logger();
		$this->api        = new KFI_API( $this->logger );
		$this->downloader = new KFI_Downloader( $this->logger );
		$this->zipper     = new KFI_Zipper( $this->logger );
		$this->publisher  = new KFI_Publisher( $this->logger );
		$this->cron       = new KFI_Cron( $this );
		$this->featured_image = new KFI_Featured_Image( $this->logger );
		$this->frontend   = new KFI_Frontend();
		$this->admin      = new KFI_Admin_UI( $this );

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'kreativ-font-ingestor', false, dirname( plugin_basename( KFI_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $wpdb->prefix . KFI_TABLE_IMPORTS;
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			font_family varchar(191) NOT NULL,
			font_slug varchar(191) NOT NULL,
			post_id bigint(20) unsigned DEFAULT 0,
			zip_url text NOT NULL,
			source_hash varchar(64) NOT NULL,
			imported_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY font_slug (font_slug)
		) {$charset_collate};";

		dbDelta( $sql );

		$defaults = array(
			'api_key'                    => '',
			'cron_enabled'               => 1,
			'import_limit'               => 10,
			'category_id'                => 0,
			'affiliate_html'             => '',
			'taxonomy_parent_fonts'      => 'Fonts',
			'taxonomy_parent_designer'   => 'Designer',
			'taxonomy_parent_foundry'    => 'Foundry',
			'taxonomy_parent_style'      => 'Font Style',
			'taxonomy_parent_mood'       => 'Font Mood',
			'taxonomy_parent_use_case'   => 'Font Use Case',
		);

		add_option( KFI_OPTION_SETTINGS, $defaults );
		$logger = new KFI_Logger();
		$logger->ensure_base_paths();
		$logger->info( 'Plugin activated.' );

		add_filter( 'cron_schedules', array( 'KFI_Cron', 'add_schedule' ) );
		KFI_Cron::register_schedule();
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( KFI_CRON_HOOK );

		$logger = new KFI_Logger();
		$logger->info( 'Plugin deactivated.' );
	}

	/**
	 * Fetch normalized settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings() {
		$settings = get_option( KFI_OPTION_SETTINGS, array() );

		return wp_parse_args(
			is_array( $settings ) ? $settings : array(),
				array(
					'api_key'                  => '',
					'cron_enabled'             => 1,
					'import_limit'             => 10,
					'category_id'              => 0,
					'affiliate_html'           => '',
					'taxonomy_parent_fonts'    => 'Fonts',
					'taxonomy_parent_designer' => 'Designer',
					'taxonomy_parent_foundry'  => 'Foundry',
					'taxonomy_parent_style'    => 'Font Style',
					'taxonomy_parent_mood'     => 'Font Mood',
					'taxonomy_parent_use_case' => 'Font Use Case',
				)
			);
	}

	/**
	 * Run import process.
	 *
	 * @param int  $limit  Max number of fonts.
	 * @param bool $manual Whether this is a manual run.
	 * @return array<string, mixed>
	 */
	public function run_import( $limit = 10, $manual = false ) {
		$limit    = max( 1, absint( $limit ) );
		$settings = $this->get_settings();

		if ( empty( $settings['api_key'] ) ) {
			$message = __( 'Google Fonts API key is missing.', 'kreativ-font-ingestor' );
			$this->logger->error( $message );

			return array(
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => array( $message ),
			);
		}

		if ( get_transient( KFI_IMPORT_LOCK ) ) {
			$message = __( 'Import skipped because another process is already running.', 'kreativ-font-ingestor' );
			$this->logger->info( $message );

			return array(
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => array( $message ),
			);
		}

		set_transient( KFI_IMPORT_LOCK, 1, 10 * MINUTE_IN_SECONDS );
		ignore_user_abort( true );

		$results = array(
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => array(),
		);

		try {
			$this->logger->info(
				sprintf(
					'Starting %s import run. Limit: %d',
					$manual ? 'manual' : 'scheduled',
					$limit
				)
			);

			$fonts = $this->api->get_fonts( $settings['api_key'] );

			if ( is_wp_error( $fonts ) ) {
				$this->logger->error( $fonts->get_error_message() );
				$results['errors'][] = $fonts->get_error_message();

				return $results;
			}

			$eligible_fonts = array();

			foreach ( $fonts as $font ) {
				$font_family = isset( $font['family'] ) ? sanitize_text_field( $font['family'] ) : '';

				if ( empty( $font_family ) ) {
					++$results['skipped'];
					continue;
				}

				$font_slug = sanitize_title( $font_family );

				if ( $this->is_font_imported( $font_slug ) ) {
					++$results['skipped'];
					continue;
				}

				$eligible_fonts[] = $font;
			}

			$total_fonts = count( $eligible_fonts );

			if ( 0 === $total_fonts ) {
				$this->logger->info( 'No eligible unimported fonts were found for this run.' );
				return $results;
			}

			$deadline = time() + 20;
			shuffle( $eligible_fonts );

			foreach ( $eligible_fonts as $font ) {
				if ( $results['imported'] >= $limit || time() >= $deadline ) {
					break;
				}

				$font_family = isset( $font['family'] ) ? sanitize_text_field( $font['family'] ) : '';
				$font_slug   = sanitize_title( $font_family );

				$download = $this->downloader->download_font_family( $font );

				if ( is_wp_error( $download ) ) {
					$results['errors'][] = sprintf( '%s: %s', $font_family, $download->get_error_message() );
					$this->logger->error( sprintf( 'Download failed for %s. %s', $font_family, $download->get_error_message() ) );
					continue;
				}

				$zip_file = $this->zipper->create_zip( $download['folder_path'], $font_slug );

				if ( is_wp_error( $zip_file ) ) {
					$results['errors'][] = sprintf( '%s: %s', $font_family, $zip_file->get_error_message() );
					$this->logger->error( sprintf( 'ZIP failed for %s. %s', $font_family, $zip_file->get_error_message() ) );
					continue;
				}

				$post_id = $this->publisher->create_post( $font, $download, $zip_file, $settings );

				if ( is_wp_error( $post_id ) ) {
					$results['errors'][] = sprintf( '%s: %s', $font_family, $post_id->get_error_message() );
					$this->logger->error( sprintf( 'Publishing failed for %s. %s', $font_family, $post_id->get_error_message() ) );
					continue;
				}

				$featured_image = $this->featured_image->generate_for_post( $post_id, $download, $font );

				if ( is_wp_error( $featured_image ) ) {
					$results['errors'][] = sprintf( '%s: %s', $font_family, $featured_image->get_error_message() );
					$this->logger->error( sprintf( 'Featured image generation failed for %s. %s', $font_family, $featured_image->get_error_message() ) );
				} else {
					$refresh_post = $this->publisher->refresh_post_content( $post_id, $font, $download, $zip_file, $settings );

					if ( is_wp_error( $refresh_post ) ) {
						$results['errors'][] = sprintf( '%s: %s', $font_family, $refresh_post->get_error_message() );
						$this->logger->error( sprintf( 'Post content refresh failed for %s. %s', $font_family, $refresh_post->get_error_message() ) );
					}
				}

				$this->mark_font_imported(
					array(
						'font_family' => $font_family,
						'font_slug'   => $font_slug,
						'post_id'     => $post_id,
						'zip_url'     => $zip_file['zip_url'],
						'source_hash' => hash( 'sha256', wp_json_encode( $font ) ),
					)
				);

				++$results['imported'];

				if ( function_exists( 'set_time_limit' ) ) {
					@set_time_limit( 20 );
				}
			}

			$this->logger->info(
				sprintf(
					'Import finished. Imported: %d. Skipped: %d. Errors: %d',
					$results['imported'],
					$results['skipped'],
					count( $results['errors'] )
				)
			);

			return $results;
		} finally {
			delete_transient( KFI_IMPORT_LOCK );
		}
	}

	/**
	 * Check if font was already imported.
	 *
	 * @param string $font_slug Font slug.
	 * @return bool
	 */
	public function is_font_imported( $font_slug ) {
		global $wpdb;

		$table_name = $wpdb->prefix . KFI_TABLE_IMPORTS;
		$exists     = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE font_slug = %s LIMIT 1", $font_slug ) );

		return ! empty( $exists );
	}

	/**
	 * Persist import record.
	 *
	 * @param array<string, mixed> $data Row data.
	 * @return void
	 */
	public function mark_font_imported( array $data ) {
		global $wpdb;

		$table_name = $wpdb->prefix . KFI_TABLE_IMPORTS;

		$wpdb->replace(
			$table_name,
			array(
				'font_family' => sanitize_text_field( $data['font_family'] ),
				'font_slug'   => sanitize_title( $data['font_slug'] ),
				'post_id'     => absint( $data['post_id'] ),
				'zip_url'     => esc_url_raw( $data['zip_url'] ),
				'source_hash' => sanitize_text_field( $data['source_hash'] ),
				'imported_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Expose logger instance.
	 *
	 * @return KFI_Logger
	 */
	public function get_logger() {
		return $this->logger;
	}
}

register_activation_hook( KFI_PLUGIN_FILE, array( 'KFI_Plugin', 'activate' ) );
register_deactivation_hook( KFI_PLUGIN_FILE, array( 'KFI_Plugin', 'deactivate' ) );

KFI_Plugin::instance();
