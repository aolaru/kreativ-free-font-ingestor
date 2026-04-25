<?php
/**
 * Download tracking service.
 *
 * @package KreativFontIngestor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KFI_Download_Tracker {
	/**
	 * Seconds to suppress duplicate tracked download events.
	 */
	const DEDUPE_WINDOW = 10;

	/**
	 * Logger instance.
	 *
	 * @var KFI_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param KFI_Logger $logger Logger.
	 */
	public function __construct( KFI_Logger $logger ) {
		$this->logger = $logger;

		add_action( 'template_redirect', array( $this, 'handle_download_request' ), 1 );
	}

	/**
	 * Build a tracked download URL for a font post.
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $fallback_url Direct file URL fallback.
	 * @param string $asset        Asset type.
	 * @return string
	 */
	public function get_download_url( $post_id, $fallback_url = '', $asset = 'zip' ) {
		$post_id = absint( $post_id );
		$asset   = 'webfont' === sanitize_key( $asset ) ? 'webfont' : 'zip';

		if ( ! $post_id ) {
			return esc_url_raw( $fallback_url );
		}

		$args = array(
			'kfi_download' => $post_id,
		);

		if ( 'webfont' === $asset ) {
			$args['kfi_asset'] = 'webfont';
		}

		return esc_url_raw( add_query_arg( $args, home_url( '/' ) ) );
	}

	/**
	 * Handle tracked download redirects.
	 *
	 * @return void
	 */
	public function handle_download_request() {
		if ( empty( $_GET['kfi_download'] ) ) {
			return;
		}

		$post_id    = absint( wp_unslash( $_GET['kfi_download'] ) );
		$asset      = ! empty( $_GET['kfi_asset'] ) ? sanitize_key( wp_unslash( $_GET['kfi_asset'] ) ) : 'zip';
		$meta_key   = 'webfont' === $asset ? '_kfi_webfont_zip_url' : '_kfi_zip_url';
		$asset_url  = esc_url_raw( get_post_meta( $post_id, $meta_key, true ) );

		if ( ! $post_id || '' === $asset_url || 'publish' !== get_post_status( $post_id ) ) {
			wp_die( esc_html__( 'Download could not be started.', 'kreativ-font-ingestor' ), 404 );
		}

		$this->record_download( $post_id, $asset );
		wp_redirect( $asset_url, 302, 'Kreativ Font Ingestor' );
		exit;
	}

	/**
	 * Record one tracked download event.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $asset   Asset type.
	 * @return void
	 */
	public function record_download( $post_id, $asset = 'zip' ) {
		global $wpdb;

		$post_id     = absint( $post_id );
		$asset       = 'webfont' === sanitize_key( $asset ) ? 'webfont' : 'zip';
		$table_name  = $wpdb->prefix . KFI_TABLE_DOWNLOADS;
		$font_family = sanitize_text_field( get_post_meta( $post_id, '_kfi_font_family', true ) );
		$referrer    = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$user_agent  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$ip_source   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip_hash     = '' !== $ip_source ? hash( 'sha256', $ip_source ) : '';
		$ua_hash     = '' !== $user_agent ? hash( 'sha256', $user_agent ) : 'noua';
		$count       = (int) get_post_meta( $post_id, '_kfi_download_count', true );
		$dedupe_key  = sprintf(
			'kfi_dl_%s',
			hash( 'sha256', implode( '|', array( $post_id, $asset, $ip_hash, $ua_hash ) ) )
		);

		if ( get_transient( $dedupe_key ) ) {
			$this->logger->info( sprintf( 'Deduped %s download for post #%d (%s).', $asset, $post_id, $font_family ) );
			return;
		}

		$wpdb->insert(
			$table_name,
			array(
				'post_id'       => $post_id,
				'font_family'   => $font_family,
				'referrer_url'  => $referrer,
				'user_agent'    => $user_agent,
				'ip_hash'       => $ip_hash,
				'downloaded_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		update_post_meta( $post_id, '_kfi_download_count', $count + 1 );
		update_post_meta( $post_id, '_kfi_last_download_at', current_time( 'mysql', true ) );
		set_transient( $dedupe_key, 1, self::DEDUPE_WINDOW );

		$this->logger->info( sprintf( 'Tracked %s download for post #%d (%s).', $asset, $post_id, $font_family ) );
	}

	/**
	 * Get top downloaded font posts.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_top_downloads( $limit = 10 ) {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => max( 1, absint( $limit ) ),
				'meta_key'       => '_kfi_download_count',
				'orderby'        => 'meta_value_num',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'     => '_kfi_font_family',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$rows = array();

		foreach ( $posts as $post ) {
			$rows[] = array(
				'post_id'        => $post->ID,
				'title'          => get_the_title( $post ),
				'font_family'    => sanitize_text_field( get_post_meta( $post->ID, '_kfi_font_family', true ) ),
				'download_count' => (int) get_post_meta( $post->ID, '_kfi_download_count', true ),
				'last_download'  => sanitize_text_field( get_post_meta( $post->ID, '_kfi_last_download_at', true ) ),
			);
		}

		return $rows;
	}

	/**
	 * Get recent download events.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_recent_downloads( $limit = 20 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . KFI_TABLE_DOWNLOADS;
		$limit      = max( 1, absint( $limit ) );

		$query = $wpdb->prepare(
			"SELECT id, post_id, font_family, referrer_url, downloaded_at FROM {$table_name} ORDER BY downloaded_at DESC LIMIT %d",
			$limit
		);

		$rows = $wpdb->get_results( $query, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get summary download stats.
	 *
	 * @return array<string, int>
	 */
	public function get_overview_stats() {
		global $wpdb;

		$table_name = $wpdb->prefix . KFI_TABLE_DOWNLOADS;

		return array(
			'total'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ),
			'today'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE downloaded_at >= UTC_DATE()" ),
			'week'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE downloaded_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)" ),
			'month'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE downloaded_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)" ),
		);
	}
}
