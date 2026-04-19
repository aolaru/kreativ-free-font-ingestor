<?php
/**
 * Frontend rendering support.
 *
 * @package KreativFontIngestor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KFI_Frontend {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_head', array( $this, 'output_meta_tags' ), 5 );
	}

	/**
	 * Enqueue assets for imported font posts.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		$post_id     = get_queried_object_id();
		$font_family = get_post_meta( $post_id, '_kfi_font_family', true );

		if ( empty( $font_family ) ) {
			return;
		}

		wp_enqueue_style(
			'kfi-frontend',
			KFI_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			KFI_VERSION
		);

	}

	/**
	 * Output SEO meta tags and JSON-LD for imported font posts.
	 *
	 * @return void
	 */
	public function output_meta_tags() {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		$post_id     = get_queried_object_id();
		$font_family = get_post_meta( $post_id, '_kfi_font_family', true );

		if ( empty( $font_family ) ) {
			return;
		}

		$zip_url        = esc_url( get_post_meta( $post_id, '_kfi_zip_url', true ) );
		$tracked_url    = class_exists( 'KFI_Plugin' ) ? KFI_Plugin::instance()->get_download_tracker()->get_download_url( $post_id, $zip_url, 'zip' ) : $zip_url;
		$zip_size       = sanitize_text_field( get_post_meta( $post_id, '_kfi_zip_size_human', true ) );
		$variant_count  = absint( get_post_meta( $post_id, '_kfi_variant_count', true ) );
		$subsets        = get_post_meta( $post_id, '_kfi_subsets', true );
		$description_meta = sanitize_text_field( get_post_meta( $post_id, '_kfi_font_description', true ) );
		$description      = $description_meta ? $description_meta : sprintf(
			'Download %1$s font for free with commercial use guidance, local ZIP package, OFL license file, and generated specimen image.',
			sanitize_text_field( $font_family )
		);
		$has_seo_plugin = defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' );
		$schema         = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'CreativeWork',
			'name'                => sprintf( '%s Font Free Download (Commercial Use)', sanitize_text_field( $font_family ) ),
			'headline'            => sprintf( '%s Font Free Download (Commercial Use)', sanitize_text_field( $font_family ) ),
			'description'         => $description,
			'url'                 => get_permalink( $post_id ),
			'datePublished'       => get_the_date( 'c', $post_id ),
			'dateModified'        => get_the_modified_date( 'c', $post_id ),
			'publisher'           => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
			),
			'keywords'            => implode( ', ', array_filter( array( sanitize_text_field( $font_family ), 'free font', 'commercial use font', 'Google Fonts' ) ) ),
			'encodingFormat'      => 'application/zip',
			'license'             => get_post_meta( $post_id, '_kfi_license_source_url', true ),
			'isAccessibleForFree' => true,
			'distribution'        => array(
				'@type'          => 'DataDownload',
				'contentUrl'     => $tracked_url,
				'encodingFormat' => 'application/zip',
				'contentSize'    => $zip_size,
			),
			'additionalProperty'  => array(
				array(
					'@type' => 'PropertyValue',
					'name'  => 'Variant count',
					'value' => $variant_count,
				),
				array(
					'@type' => 'PropertyValue',
					'name'  => 'Subsets',
					'value' => is_array( $subsets ) ? implode( ', ', array_map( 'sanitize_text_field', $subsets ) ) : '',
				),
			),
		);

		if ( ! $has_seo_plugin ) {
			printf( "<meta name=\"description\" content=\"%s\" />\n", esc_attr( $description ) );
			printf( "<meta property=\"og:type\" content=\"article\" />\n" );
			printf( "<meta property=\"og:title\" content=\"%s\" />\n", esc_attr( get_the_title( $post_id ) ) );
			printf( "<meta property=\"og:description\" content=\"%s\" />\n", esc_attr( $description ) );
			printf( "<meta property=\"og:url\" content=\"%s\" />\n", esc_url( get_permalink( $post_id ) ) );
			printf( "<meta name=\"twitter:card\" content=\"summary_large_image\" />\n" );
		}

		printf( "<script type=\"application/ld+json\">%s</script>\n", wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

}
