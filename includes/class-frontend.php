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
		add_filter( 'script_loader_tag', array( $this, 'mark_script_as_module' ), 10, 3 );
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

		$google_preview_url = $this->build_google_preview_url( $post_id, $font_family );

		if ( $google_preview_url ) {
			wp_enqueue_style(
				'kfi-google-preview',
				$google_preview_url,
				array(),
				null
			);
		}

		wp_enqueue_script(
			'kfi-frontend',
			KFI_PLUGIN_URL . 'assets/js/frontend.js',
			array(),
			KFI_VERSION,
			true
		);

		$preview_alias = 'KFI Preview ' . absint( $post_id );
		$config = array(
			'fontFamily'    => sanitize_text_field( $font_family ),
			'previewAlias'  => $preview_alias,
			'previewStack'  => $this->build_preview_stack( $font_family, $preview_alias ),
			'googlePreview' => (bool) $google_preview_url,
			'previewUrl'    => esc_url_raw( get_post_meta( $post_id, '_kfi_preview_asset_url', true ) ? get_post_meta( $post_id, '_kfi_preview_asset_url', true ) : get_post_meta( $post_id, '_kfi_preview_font_url', true ) ),
			'previewFormat' => sanitize_text_field( get_post_meta( $post_id, '_kfi_preview_asset_format', true ) ? get_post_meta( $post_id, '_kfi_preview_asset_format', true ) : get_post_meta( $post_id, '_kfi_preview_font_format', true ) ),
			'previewStatus' => sanitize_text_field( get_post_meta( $post_id, '_kfi_preview_asset_status', true ) ),
		);

		wp_add_inline_script( 'kfi-frontend', 'window.KFIPreviewConfig = ' . wp_json_encode( $config ) . ';', 'before' );

		$preview_url = $config['previewUrl'];

		if ( ! empty( $preview_url ) ) {
			$src_parts = array(
				sprintf( 'url("%1$s")', esc_url_raw( $preview_url ) ),
			);

			if ( ! empty( $config['previewFormat'] ) ) {
				$src_parts[] = sprintf( 'url("%1$s") format("%2$s")', esc_url_raw( $preview_url ), esc_attr( $config['previewFormat'] ) );
			}

			$font_face = sprintf(
				'@font-face{font-family:"%1$s";src:%2$s;font-display:swap;font-style:normal;font-weight:400;}',
				esc_attr( $preview_alias ),
				implode( ',', $src_parts )
			);
			wp_add_inline_style( 'kfi-frontend', $font_face );
		}
	}

	/**
	 * Build Google Fonts stylesheet URL for live preview.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $font_family Font family.
	 * @return string
	 */
	private function build_google_preview_url( $post_id, $font_family ) {
		$post_id     = absint( $post_id );
		$font_family = sanitize_text_field( $font_family );

		if ( ! $post_id || '' === $font_family ) {
			return '';
		}

		$variants = get_post_meta( $post_id, '_kfi_variants', true );
		$variants = is_array( $variants ) ? array_map( 'sanitize_text_field', $variants ) : array();
		$family   = $this->build_google_css_family_query( $font_family, $variants );

		if ( '' === $family ) {
			return '';
		}

		return esc_url_raw(
			add_query_arg(
				array(
					'family'  => $family,
					'display' => 'swap',
				),
				'https://fonts.googleapis.com/css2'
			)
		);
	}

	/**
	 * Build a preview font-family stack with Google family first and local alias fallback second.
	 *
	 * @param string $font_family   Font family.
	 * @param string $preview_alias Local preview alias.
	 * @return string
	 */
	private function build_preview_stack( $font_family, $preview_alias ) {
		return sprintf(
			'"%1$s","%2$s",sans-serif',
			esc_js( sanitize_text_field( $font_family ) ),
			esc_js( sanitize_text_field( $preview_alias ) )
		);
	}

	/**
	 * Convert stored variant meta to a Google Fonts css2 family query.
	 *
	 * @param string            $font_family Font family.
	 * @param array<int,string> $variants    Stored variants.
	 * @return string
	 */
	private function build_google_css_family_query( $font_family, array $variants ) {
		$family = str_replace( ' ', '+', trim( sanitize_text_field( $font_family ) ) );

		if ( '' === $family ) {
			return '';
		}

		$normal_weights = array();
		$italic_weights = array();

		foreach ( $variants as $variant ) {
			$variant = strtolower( trim( $variant ) );

			if ( '' === $variant ) {
				continue;
			}

			if ( 'regular' === $variant ) {
				$normal_weights[] = 400;
				continue;
			}

			if ( 'italic' === $variant ) {
				$italic_weights[] = 400;
				continue;
			}

			if ( preg_match( '/^(\d{3})italic$/', $variant, $matches ) ) {
				$italic_weights[] = (int) $matches[1];
				continue;
			}

			if ( preg_match( '/^\d{3}$/', $variant ) ) {
				$normal_weights[] = (int) $variant;
			}
		}

		$normal_weights = array_values( array_unique( array_filter( $normal_weights ) ) );
		$italic_weights = array_values( array_unique( array_filter( $italic_weights ) ) );
		sort( $normal_weights );
		sort( $italic_weights );

		if ( empty( $normal_weights ) && empty( $italic_weights ) ) {
			return $family;
		}

		if ( ! empty( $italic_weights ) ) {
			if ( empty( $normal_weights ) ) {
				$normal_weights[] = 400;
			}

			$pairs = array();

			foreach ( $normal_weights as $weight ) {
				$pairs[] = '0,' . $weight;
			}

			foreach ( $italic_weights as $weight ) {
				$pairs[] = '1,' . $weight;
			}

			return $family . ':ital,wght@' . implode( ';', $pairs );
		}

		return $family . ':wght@' . implode( ';', $normal_weights );
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

		$zip_url      = esc_url( get_post_meta( $post_id, '_kfi_zip_url', true ) );
		$tracked_url  = class_exists( 'KFI_Plugin' ) ? KFI_Plugin::instance()->get_download_tracker()->get_download_url( $post_id, $zip_url ) : $zip_url;
		$zip_size     = sanitize_text_field( get_post_meta( $post_id, '_kfi_zip_size_human', true ) );
		$variant_count = absint( get_post_meta( $post_id, '_kfi_variant_count', true ) );
		$subsets      = get_post_meta( $post_id, '_kfi_subsets', true );
		$description_meta = sanitize_text_field( get_post_meta( $post_id, '_kfi_font_description', true ) );
		$description      = $description_meta ? $description_meta : sprintf(
			'Download %1$s font for free with commercial use guidance, local ZIP package, OFL license file, and on-page live preview.',
			sanitize_text_field( $font_family )
		);
		$has_seo_plugin = defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' );
		$schema       = array(
			'@context'           => 'https://schema.org',
			'@type'              => 'CreativeWork',
			'name'               => sprintf( '%s Font Free Download (Commercial Use)', sanitize_text_field( $font_family ) ),
			'headline'           => sprintf( '%s Font Free Download (Commercial Use)', sanitize_text_field( $font_family ) ),
			'description'        => $description,
			'url'                => get_permalink( $post_id ),
			'datePublished'      => get_the_date( 'c', $post_id ),
			'dateModified'       => get_the_modified_date( 'c', $post_id ),
			'publisher'          => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
			),
			'keywords'           => implode( ', ', array_filter( array( sanitize_text_field( $font_family ), 'free font', 'commercial use font', 'Google Fonts' ) ) ),
			'encodingFormat'     => 'application/zip',
			'license'            => get_post_meta( $post_id, '_kfi_license_source_url', true ),
			'isAccessibleForFree' => true,
			'distribution'       => array(
				'@type'          => 'DataDownload',
				'contentUrl'     => $tracked_url,
				'encodingFormat' => 'application/zip',
				'contentSize'    => $zip_size,
			),
			'additionalProperty' => array(
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

	/**
	 * Mark frontend script as a module.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Handle.
	 * @param string $src    Source.
	 * @return string
	 */
	public function mark_script_as_module( $tag, $handle, $src ) {
		if ( 'kfi-frontend' !== $handle ) {
			return $tag;
		}

		return sprintf( '<script type="module" src="%1$s"></script>', esc_url( $src ) );
	}
}
