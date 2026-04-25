<?php
/**
 * Plugin Name: Kreativ Free Fonts
 * Plugin URI: https://example.com/kreativ-font-ingestor
 * Description: Imports open-source Google Fonts, stores them locally with OFL licensing, generates ZIP packages, and publishes SEO-ready WordPress posts.
 * Version: 1.1.1
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

define( 'KFI_VERSION', '1.1.1' );
define( 'KFI_PLUGIN_FILE', __FILE__ );
define( 'KFI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KFI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'KFI_OPTION_SETTINGS', 'kfi_settings' );
define( 'KFI_OPTION_IMPORT_QUEUE', 'kfi_import_queue' );
define( 'KFI_TRANSIENT_FONTS', 'kfi_google_fonts_list' );
define( 'KFI_CRON_HOOK', 'kfi_cron_import_fonts' );
define( 'KFI_IMPORT_LOCK', 'kfi_import_lock' );
define( 'KFI_TABLE_IMPORTS', 'kfi_imported_fonts' );
define( 'KFI_TABLE_DOWNLOADS', 'kfi_download_events' );
define( 'KFI_DB_VERSION', '1.0.0' );

require_once KFI_PLUGIN_DIR . 'includes/class-logger.php';
require_once KFI_PLUGIN_DIR . 'includes/class-api.php';
require_once KFI_PLUGIN_DIR . 'includes/class-enrichment.php';
require_once KFI_PLUGIN_DIR . 'includes/class-downloader.php';
require_once KFI_PLUGIN_DIR . 'includes/class-zipper.php';
require_once KFI_PLUGIN_DIR . 'includes/class-preview-assets.php';
require_once KFI_PLUGIN_DIR . 'includes/class-publisher.php';
require_once KFI_PLUGIN_DIR . 'includes/class-cron.php';
require_once KFI_PLUGIN_DIR . 'includes/class-featured-image.php';
require_once KFI_PLUGIN_DIR . 'includes/class-frontend.php';
require_once KFI_PLUGIN_DIR . 'includes/class-download-tracker.php';
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
	 * Enrichment service.
	 *
	 * @var KFI_Enrichment
	 */
	private $enrichment;

	/**
	 * Zipper.
	 *
	 * @var KFI_Zipper
	 */
	private $zipper;

	/**
	 * Preview asset manager.
	 *
	 * @var KFI_Preview_Assets
	 */
	private $preview_assets;

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
	 * Download tracker.
	 *
	 * @var KFI_Download_Tracker
	 */
	private $download_tracker;

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
		$this->enrichment = new KFI_Enrichment( $this->logger, $this->api );
		$this->downloader = new KFI_Downloader( $this->logger );
		$this->zipper     = new KFI_Zipper( $this->logger );
		$this->preview_assets = new KFI_Preview_Assets( $this->logger );
		$this->publisher  = new KFI_Publisher( $this->logger );
		$this->cron       = new KFI_Cron( $this );
		$this->featured_image = new KFI_Featured_Image( $this->logger );
		$this->frontend   = new KFI_Frontend();
		$this->download_tracker = new KFI_Download_Tracker( $this->logger );
		$this->admin      = new KFI_Admin_UI( $this );

		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ), 5 );
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Ensure database schema is up to date after plugin updates.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		$stored_version = get_option( 'kfi_db_version', '' );

		if ( KFI_DB_VERSION === $stored_version ) {
			$this->maybe_upgrade_settings();
			return;
		}

		self::create_database_schema();
		update_option( 'kfi_db_version', KFI_DB_VERSION );
		$this->maybe_upgrade_settings();
	}

	/**
	 * Normalize settings defaults for existing installs.
	 *
	 * @return void
	 */
	private function maybe_upgrade_settings() {
		$settings = get_option( KFI_OPTION_SETTINGS, array() );

		if ( ! is_array( $settings ) ) {
			return;
		}

		$updated = false;

		if ( ! isset( $settings['import_limit'] ) || in_array( absint( $settings['import_limit'] ), array( 5, 10 ), true ) ) {
			$settings['import_limit'] = 3;
			$updated                  = true;
		}

		if ( $updated ) {
			update_option( KFI_OPTION_SETTINGS, $settings );
		}
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
		self::create_database_schema();
		update_option( 'kfi_db_version', KFI_DB_VERSION );

		$defaults = array(
			'api_key'                    => '',
			'cron_enabled'               => 1,
			'import_limit'               => 3,
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
	 * Create or update plugin database tables.
	 *
	 * @return void
	 */
	private static function create_database_schema() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $wpdb->prefix . KFI_TABLE_IMPORTS;
		$downloads_table = $wpdb->prefix . KFI_TABLE_DOWNLOADS;
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

		$downloads_sql = "CREATE TABLE {$downloads_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			font_family varchar(191) NOT NULL,
			referrer_url text NOT NULL,
			user_agent text NOT NULL,
			ip_hash varchar(64) NOT NULL,
			downloaded_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY downloaded_at (downloaded_at)
		) {$charset_collate};";

		dbDelta( $downloads_sql );
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( KFI_CRON_HOOK );
		delete_option( KFI_OPTION_IMPORT_QUEUE );

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
					'import_limit'             => 3,
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
	public function run_import( $limit = 3, $manual = false ) {
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

			$catalog_index = $this->build_font_catalog_index( $fonts );
			$queue         = $this->get_import_queue( $fonts );

			if ( empty( $queue['pending'] ) ) {
				$this->logger->info( 'No eligible unimported fonts were found for this run.' );
				return $results;
			}

			$deadline = time() + 20;
			$processed = 0;

			while ( $results['imported'] < $limit && time() < $deadline && ! empty( $queue['pending'] ) ) {
				$font_slug = array_shift( $queue['pending'] );

				if ( empty( $font_slug ) ) {
					continue;
				}

				if ( $this->is_font_imported( $font_slug ) ) {
					++$results['skipped'];
					continue;
				}

				if ( empty( $catalog_index[ $font_slug ] ) ) {
					++$results['skipped'];
					continue;
				}

				$font        = $catalog_index[ $font_slug ];
				$font_family = isset( $font['family'] ) ? sanitize_text_field( $font['family'] ) : '';
				$font        = $this->enrichment->enrich_font( $font );
				++$processed;

				$download = $this->downloader->download_font_family( $font );

				if ( is_wp_error( $download ) ) {
					$results['errors'][] = sprintf( '%s: %s', $font_family, $download->get_error_message() );
					$this->logger->error( sprintf( 'Download failed for %s. %s%s', $font_family, $download->get_error_message(), $this->format_error_diagnostics( $download ) ) );
					continue;
				}

				$zip_file = $this->zipper->create_zip( $download['folder_path'], $font_slug );

				if ( is_wp_error( $zip_file ) ) {
					$results['errors'][] = sprintf( '%s: %s', $font_family, $zip_file->get_error_message() );
					$this->logger->error( sprintf( 'ZIP failed for %s. %s', $font_family, $zip_file->get_error_message() ) );
					continue;
				}

				$preview_asset = $this->preview_assets->ensure_preview_asset( $download );

				if ( is_wp_error( $preview_asset ) ) {
					$this->logger->error( sprintf( 'Preview asset preparation failed for %s. %s', $font_family, $preview_asset->get_error_message() ) );
				} else {
					$download['preview_asset'] = $preview_asset;
					$webfont_kit               = $this->preview_assets->ensure_webfont_kit( $download, $preview_asset );

					if ( is_wp_error( $webfont_kit ) ) {
						$this->logger->info( sprintf( 'Webfont kit skipped for %s. %s', $font_family, $webfont_kit->get_error_message() ) );
					} else {
						$download['webfont_kit'] = $webfont_kit;
					}
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

			$queue['last_run_at']       = current_time( 'mysql', true );
			$queue['last_processed']    = $processed;
			$queue['last_imported']     = $results['imported'];
			$queue['last_error_count']  = count( $results['errors'] );
			$this->save_import_queue( $queue );

			$this->logger->info(
				sprintf(
					'Import finished. Imported: %d. Skipped: %d. Errors: %d. Queue remaining: %d',
					$results['imported'],
					$results['skipped'],
					count( $results['errors'] ),
					isset( $queue['pending'] ) && is_array( $queue['pending'] ) ? count( $queue['pending'] ) : 0
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
	 * Regenerate one or more imported posts from current local/API data.
	 *
	 * @param int $post_id Optional single post ID.
	 * @param int $limit   Batch size when no post ID is provided.
	 * @return array<string, mixed>
	 */
	public function regenerate_imported_posts( $post_id = 0, $limit = 10 ) {
		$settings = $this->get_settings();
		$results  = array(
			'regenerated' => 0,
			'errors'      => array(),
		);
		$post_ids  = $post_id ? array( absint( $post_id ) ) : $this->get_regeneration_post_ids( $limit );

		foreach ( $post_ids as $target_post_id ) {
			$result = $this->regenerate_post( $target_post_id, $settings );

			if ( is_wp_error( $result ) ) {
				$results['errors'][] = sprintf( '#%1$d: %2$s', $target_post_id, $result->get_error_message() );
				$this->logger->error( sprintf( 'Regeneration failed for post #%1$d. %2$s', $target_post_id, $result->get_error_message() ) );
				continue;
			}

			++$results['regenerated'];
		}

		$this->logger->info(
			sprintf(
				'Regen finished. Regenerated: %d. Errors: %d',
				$results['regenerated'],
				count( $results['errors'] )
			)
		);

		return $results;
	}

	/**
	 * Backfill managed preview assets and optional webfont kits.
	 *
	 * @param int $post_id Single post ID or 0 for batch mode.
	 * @param int $limit   Batch size.
	 * @return array<string, mixed>
	 */
	public function backfill_preview_assets( $post_id = 0, $limit = 25 ) {
		$settings = $this->get_settings();
		$results  = array(
			'updated' => 0,
			'errors'  => array(),
		);
		$post_ids = $post_id ? array( absint( $post_id ) ) : $this->get_regeneration_post_ids( $limit );

		foreach ( $post_ids as $target_post_id ) {
			$result = $this->backfill_preview_assets_for_post( $target_post_id, $settings );

			if ( is_wp_error( $result ) ) {
				$results['errors'][] = sprintf( '#%1$d: %2$s', $target_post_id, $result->get_error_message() );
				$this->logger->error( sprintf( 'Preview backfill failed for post #%1$d. %2$s', $target_post_id, $result->get_error_message() ) );
				continue;
			}

			++$results['updated'];
		}

		$this->logger->info(
			sprintf(
				'Preview backfill finished. Updated: %d. Errors: %d',
				$results['updated'],
				count( $results['errors'] )
			)
		);

		return $results;
	}

	/**
	 * Regenerate a single imported post.
	 *
	 * @param int                  $post_id  Post ID.
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return true|WP_Error
	 */
	private function regenerate_post( $post_id, array $settings ) {
		$post_id     = absint( $post_id );
		$font_family = sanitize_text_field( get_post_meta( $post_id, '_kfi_font_family', true ) );

		if ( ! $post_id || '' === $font_family ) {
			return new WP_Error( 'kfi_regenerate_invalid_post', 'This post does not appear to be an imported font post.' );
		}

		$font = $this->get_font_payload_for_post( $post_id, $font_family, $settings );

		if ( is_wp_error( $font ) ) {
			return $font;
		}

		$download = $this->build_local_download_payload( $post_id, $font );

		if ( is_wp_error( $download ) ) {
			if ( empty( $font['files'] ) || ! is_array( $font['files'] ) ) {
				return $download;
			}

			$download = $this->downloader->download_font_family( $font );

			if ( is_wp_error( $download ) ) {
				return $download;
			}
		}

		$zip_file = $this->build_or_refresh_zip_payload( $download );

		if ( is_wp_error( $zip_file ) ) {
			return $zip_file;
		}

		$preview_asset = $this->preview_assets->ensure_preview_asset( $download );

		if ( ! is_wp_error( $preview_asset ) ) {
			$download['preview_asset'] = $preview_asset;
			$webfont_kit               = $this->preview_assets->ensure_webfont_kit( $download, $preview_asset );

			if ( ! is_wp_error( $webfont_kit ) ) {
				$download['webfont_kit'] = $webfont_kit;
			}
		}

		$sync = $this->publisher->sync_post_data( $post_id, $font, $download, $zip_file, $settings );

		if ( is_wp_error( $sync ) ) {
			return $sync;
		}

		$featured = $this->featured_image->generate_for_post( $post_id, $download, $font, true );

		if ( is_wp_error( $featured ) ) {
			return $featured;
		}

		$refresh = $this->publisher->refresh_post_content( $post_id, $font, $download, $zip_file, $settings );

		if ( is_wp_error( $refresh ) ) {
			return $refresh;
		}

		$this->mark_font_imported(
			array(
				'font_family' => $font_family,
				'font_slug'   => sanitize_title( $font_family ),
				'post_id'     => $post_id,
				'zip_url'     => $zip_file['zip_url'],
				'source_hash' => hash( 'sha256', wp_json_encode( $font ) ),
			)
		);

		return true;
	}

	/**
	 * Backfill preview assets for a single imported post.
	 *
	 * @param int                  $post_id  Post ID.
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return true|WP_Error
	 */
	private function backfill_preview_assets_for_post( $post_id, array $settings ) {
		$post_id     = absint( $post_id );
		$font_family = sanitize_text_field( get_post_meta( $post_id, '_kfi_font_family', true ) );

		if ( ! $post_id || '' === $font_family ) {
			return new WP_Error( 'kfi_preview_backfill_invalid_post', 'This post does not appear to be an imported font post.' );
		}

		$font     = $this->build_fallback_font_payload( $post_id, $font_family );
		$download = $this->build_local_download_payload( $post_id, $font );

		if ( is_wp_error( $download ) ) {
			return $download;
		}

		$preview_asset = $this->preview_assets->ensure_preview_asset( $download );

		if ( is_wp_error( $preview_asset ) ) {
			return $preview_asset;
		}

		$download['preview_asset'] = $preview_asset;
		$webfont_kit               = $this->preview_assets->ensure_webfont_kit( $download, $preview_asset );

		if ( ! is_wp_error( $webfont_kit ) ) {
			$download['webfont_kit'] = $webfont_kit;
		}

		$specimen = $this->featured_image->generate_specimen_for_post( $post_id, $download, $font, true );

		if ( is_wp_error( $specimen ) ) {
			return $specimen;
		}

		$zip_file = $this->build_or_refresh_zip_payload( $download );

		if ( is_wp_error( $zip_file ) ) {
			return $zip_file;
		}

		$this->publisher->sync_generated_asset_meta( $post_id, $download );

		$refresh = $this->publisher->refresh_post_content( $post_id, $font, $download, $zip_file, $settings );

		if ( is_wp_error( $refresh ) ) {
			return $refresh;
		}

		return true;
	}

	/**
	 * Resolve the best available font payload for a post.
	 *
	 * @param int                  $post_id     Post ID.
	 * @param string               $font_family Font family.
	 * @param array<string, mixed> $settings    Plugin settings.
	 * @return array<string, mixed>|WP_Error
	 */
	private function get_font_payload_for_post( $post_id, $font_family, array $settings ) {
		$font = $this->find_font_in_catalog( $font_family, $settings );

		if ( empty( $font ) ) {
			$font = $this->build_fallback_font_payload( $post_id, $font_family );
		}

		if ( empty( $font['family'] ) ) {
			return new WP_Error( 'kfi_font_payload_missing', 'Unable to resolve a valid font payload for regeneration.' );
		}

		return $this->enrichment->enrich_font( $font );
	}

	/**
	 * Find a font in the Google Fonts catalog.
	 *
	 * @param string               $font_family Font family.
	 * @param array<string, mixed> $settings    Plugin settings.
	 * @return array<string, mixed>
	 */
	private function find_font_in_catalog( $font_family, array $settings ) {
		if ( empty( $settings['api_key'] ) ) {
			return array();
		}

		$fonts = $this->api->get_fonts( $settings['api_key'] );

		if ( is_wp_error( $fonts ) ) {
			return array();
		}

		foreach ( $fonts as $font ) {
			if ( isset( $font['family'] ) && 0 === strcasecmp( sanitize_text_field( $font['family'] ), $font_family ) ) {
				return $font;
			}
		}

		return array();
	}

	/**
	 * Build a local fallback font payload from stored post metadata.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $font_family Font family.
	 * @return array<string, mixed>
	 */
	private function build_fallback_font_payload( $post_id, $font_family ) {
		$variants = get_post_meta( $post_id, '_kfi_variants', true );
		$subsets  = get_post_meta( $post_id, '_kfi_subsets', true );

		return array(
			'family'           => $font_family,
			'category'         => sanitize_text_field( get_post_meta( $post_id, '_kfi_font_category', true ) ),
			'variants'         => is_array( $variants ) ? array_map( 'sanitize_text_field', $variants ) : array( 'regular' ),
			'subsets'          => is_array( $subsets ) ? array_map( 'sanitize_text_field', $subsets ) : array( 'latin' ),
			'designer'         => sanitize_text_field( get_post_meta( $post_id, '_kfi_font_designer', true ) ),
			'foundry'          => sanitize_text_field( get_post_meta( $post_id, '_kfi_font_foundry', true ) ),
			'description_plain'=> sanitize_text_field( get_post_meta( $post_id, '_kfi_font_description', true ) ),
			'google_fonts_url' => esc_url_raw( get_post_meta( $post_id, '_kfi_google_fonts_url', true ) ),
			'is_variable'      => (bool) get_post_meta( $post_id, '_kfi_variable_font', true ),
			'axes'             => get_post_meta( $post_id, '_kfi_variable_axes', true ),
		);
	}

	/**
	 * Build download metadata from local stored assets.
	 *
	 * @param array<string, mixed> $font Font payload.
	 * @return array<string, mixed>|WP_Error
	 */
	private function build_local_download_payload( $post_id, array $font ) {
		$paths      = $this->logger->get_upload_paths();
		$post_id    = absint( $post_id );
		$font_name  = sanitize_text_field( $font['family'] );
		$font_slug  = sanitize_title( $font_name );
		$folder     = trailingslashit( $paths['base_dir'] ) . $font_slug . '/';
		$folder_url = trailingslashit( $paths['base_url'] ) . $font_slug . '/';
		$license    = $folder . 'OFL.txt';
		$metadata   = $folder . 'metadata.json';

		if ( ! is_dir( $folder ) ) {
			return new WP_Error( 'kfi_regenerate_missing_folder', 'Local font folder is missing.' );
		}

		$files = $this->scan_local_font_files( $folder, $folder_url, $font_name );

		if ( empty( $files ) ) {
			return new WP_Error( 'kfi_regenerate_missing_files', 'No local font files were found for this family.' );
		}

		return array(
			'font_name'          => $font_name,
			'font_slug'          => $font_slug,
			'folder_path'        => $folder,
			'folder_url'         => $folder_url,
			'files'              => $files,
			'license_path'       => $license,
			'license_url'        => $folder_url . 'OFL.txt',
			'license_type'       => 'OFL-1.1',
			'license_source_url' => ! empty( $font['license_source_url'] ) ? esc_url_raw( $font['license_source_url'] ) : esc_url_raw( get_post_meta( $post_id, '_kfi_license_source_url', true ) ),
			'metadata_path'      => $metadata,
			'metadata_url'       => $folder_url . 'metadata.json',
		);
	}

	/**
	 * Scan local font files in a folder.
	 *
	 * @param string $folder     Directory path.
	 * @param string $folder_url Directory URL.
	 * @param string $font_name  Font family.
	 * @return array<int, array<string, string>>
	 */
	private function scan_local_font_files( $folder, $folder_url, $font_name ) {
		$files = glob( trailingslashit( $folder ) . '*.{woff2,woff,ttf,otf}', GLOB_BRACE );

		if ( ! is_array( $files ) ) {
			return array();
		}

		$results = array();

		foreach ( $files as $file_path ) {
			$filename   = basename( $file_path );
			$extension  = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			$variant    = preg_replace( '/^' . preg_quote( sanitize_title( $font_name ) . '-', '/' ) . '/', '', pathinfo( $filename, PATHINFO_FILENAME ) );
			$variant    = $variant ? sanitize_key( $variant ) : 'regular';
			$results[]  = array(
				'variant'   => $variant,
				'url'       => $folder_url . $filename,
				'path'      => $file_path,
				'source'    => '',
				'filename'  => $filename,
				'extension' => $extension,
			);
		}

		return $results;
	}

	/**
	 * Ensure ZIP metadata exists for a download payload.
	 *
	 * @param array<string, mixed> $download Download data.
	 * @return array<string, mixed>|WP_Error
	 */
	private function build_or_refresh_zip_payload( array $download ) {
		$paths    = $this->logger->get_upload_paths();
		$zip_name = sanitize_file_name( $download['font_slug'] . '-kreativ.zip' );
		$zip_path = $paths['packages'] . $zip_name;
		$zip_url  = $paths['packages_url'] . $zip_name;

		if ( ! file_exists( $zip_path ) ) {
			return $this->zipper->create_zip( $download['folder_path'], $download['font_slug'] );
		}

		return array(
			'zip_name' => $zip_name,
			'zip_path' => $zip_path,
			'zip_url'  => $zip_url,
			'zip_size' => (int) filesize( $zip_path ),
		);
	}

	/**
	 * Get post IDs for batch regeneration.
	 *
	 * @param int $limit Batch size.
	 * @return array<int, int>
	 */
	private function get_regeneration_post_ids( $limit ) {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => max( 1, absint( $limit ) ),
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'     => '_kfi_font_family',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return array_map( 'absint', $posts );
	}

	/**
	 * Expose logger instance.
	 *
	 * @return KFI_Logger
	 */
	public function get_logger() {
		return $this->logger;
	}

	/**
	 * Expose download tracker instance.
	 *
	 * @return KFI_Download_Tracker
	 */
	public function get_download_tracker() {
		return $this->download_tracker;
	}

	/**
	 * Expose cron controller instance.
	 *
	 * @return KFI_Cron
	 */
	public function get_cron() {
		return $this->cron;
	}

	/**
	 * Build a slug-indexed catalog for queue consumption.
	 *
	 * @param array<int, array<string, mixed>> $fonts Font catalog.
	 * @return array<string, array<string, mixed>>
	 */
	private function build_font_catalog_index( array $fonts ) {
		$index = array();

		foreach ( $fonts as $font ) {
			$family = isset( $font['family'] ) ? sanitize_text_field( $font['family'] ) : '';
			$slug   = sanitize_title( $family );

			if ( '' === $family || '' === $slug ) {
				continue;
			}

			$index[ $slug ] = $font;
		}

		return $index;
	}

	/**
	 * Get or rebuild the pending import queue.
	 *
	 * @param array<int, array<string, mixed>> $fonts Font catalog.
	 * @return array<string, mixed>
	 */
	private function get_import_queue( array $fonts ) {
		$queue        = get_option( KFI_OPTION_IMPORT_QUEUE, array() );
		$catalog_hash = $this->get_catalog_hash( $fonts );

		if ( is_array( $queue ) && isset( $queue['catalog_hash'], $queue['pending'] ) && $catalog_hash === $queue['catalog_hash'] && is_array( $queue['pending'] ) && ! empty( $queue['pending'] ) ) {
			return $queue;
		}

		$imported_lookup = $this->get_imported_slug_lookup();
		$pending         = array();

		foreach ( $fonts as $font ) {
			$family = isset( $font['family'] ) ? sanitize_text_field( $font['family'] ) : '';
			$slug   = sanitize_title( $family );

			if ( '' === $slug || isset( $imported_lookup[ $slug ] ) ) {
				continue;
			}

			$pending[] = $slug;
		}

		shuffle( $pending );

		$queue = array(
			'catalog_hash'     => $catalog_hash,
			'pending'          => $pending,
			'rebuilt_at'       => current_time( 'mysql', true ),
			'last_run_at'      => '',
			'last_processed'   => 0,
			'last_imported'    => 0,
			'last_error_count' => 0,
		);

		$this->save_import_queue( $queue );

		return $queue;
	}

	/**
	 * Persist queue state.
	 *
	 * @param array<string, mixed> $queue Queue state.
	 * @return void
	 */
	private function save_import_queue( array $queue ) {
		update_option( KFI_OPTION_IMPORT_QUEUE, $queue, false );
	}

	/**
	 * Build a lookup of imported slugs.
	 *
	 * @return array<string, bool>
	 */
	private function get_imported_slug_lookup() {
		global $wpdb;

		$table_name = $wpdb->prefix . KFI_TABLE_IMPORTS;
		$rows       = $wpdb->get_col( "SELECT font_slug FROM {$table_name}" );
		$lookup     = array();

		foreach ( (array) $rows as $slug ) {
			$slug = sanitize_title( $slug );

			if ( '' !== $slug ) {
				$lookup[ $slug ] = true;
			}
		}

		return $lookup;
	}

	/**
	 * Compute a stable hash for the current catalog.
	 *
	 * @param array<int, array<string, mixed>> $fonts Font catalog.
	 * @return string
	 */
	private function get_catalog_hash( array $fonts ) {
		$signature = array();

		foreach ( $fonts as $font ) {
			$signature[] = array(
				'family'       => isset( $font['family'] ) ? sanitize_text_field( $font['family'] ) : '',
				'lastModified' => isset( $font['lastModified'] ) ? sanitize_text_field( $font['lastModified'] ) : '',
				'version'      => isset( $font['version'] ) ? sanitize_text_field( $font['version'] ) : '',
			);
		}

		return hash( 'sha256', wp_json_encode( $signature ) );
	}

	/**
	 * Format structured WP_Error diagnostics for logs.
	 *
	 * @param WP_Error $error Error object.
	 * @return string
	 */
	private function format_error_diagnostics( WP_Error $error ) {
		$data = $error->get_error_data();

		if ( ! is_array( $data ) ) {
			return '';
		}

		$parts = array();

		if ( ! empty( $data['classification'] ) ) {
			$parts[] = 'classification=' . sanitize_text_field( $data['classification'] );
		}

		if ( ! empty( $data['matched_path'] ) ) {
			$parts[] = 'matched_path=' . sanitize_text_field( $data['matched_path'] );
		}

		if ( ! empty( $data['attempted_paths'] ) && is_array( $data['attempted_paths'] ) ) {
			$parts[] = 'attempted_paths=' . implode( ',', array_map( 'sanitize_text_field', $data['attempted_paths'] ) );
		}

		if ( ! empty( $data['request_reason'] ) ) {
			$parts[] = 'request_reason=' . sanitize_text_field( $data['request_reason'] );
		}

		return empty( $parts ) ? '' : ' [' . implode( '; ', $parts ) . ']';
	}
}

register_activation_hook( KFI_PLUGIN_FILE, array( 'KFI_Plugin', 'activate' ) );
register_deactivation_hook( KFI_PLUGIN_FILE, array( 'KFI_Plugin', 'deactivate' ) );

KFI_Plugin::instance();
