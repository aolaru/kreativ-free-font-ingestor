<?php
/**
 * Google Fonts enrichment service.
 *
 * @package KreativFontIngestor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KFI_Enrichment {
	/**
	 * Logger instance.
	 *
	 * @var KFI_Logger
	 */
	private $logger;

	/**
	 * API instance.
	 *
	 * @var KFI_API
	 */
	private $api;

	/**
	 * Constructor.
	 *
	 * @param KFI_Logger $logger Logger.
	 * @param KFI_API    $api    API.
	 */
	public function __construct( KFI_Logger $logger, KFI_API $api ) {
		$this->logger = $logger;
		$this->api    = $api;
	}

	/**
	 * Enrich a Google Fonts family with repository metadata.
	 *
	 * @param array<string, mixed> $font Font payload.
	 * @return array<string, mixed>
	 */
	public function enrich_font( array $font ) {
		$family = isset( $font['family'] ) ? sanitize_text_field( $font['family'] ) : '';

		if ( '' === $family ) {
			return $font;
		}

		$cache_key = 'kfi_font_enrichment_' . md5( strtolower( $family ) );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return array_merge( $font, $cached );
		}

		$bundle = $this->api->get_repository_bundle( $family );

		if ( is_wp_error( $bundle ) ) {
			$font['enrichment_flags'] = array( 'repository_bundle_unavailable' );
			$details                  = $bundle->get_error_data();
			$context                  = '';

			if ( is_array( $details ) ) {
				$parts = array();

				if ( ! empty( $details['classification'] ) ) {
					$parts[] = 'classification=' . sanitize_text_field( $details['classification'] );
				}

				if ( ! empty( $details['matched_path'] ) ) {
					$parts[] = 'matched_path=' . sanitize_text_field( $details['matched_path'] );
				}

				if ( ! empty( $details['attempted_paths'] ) && is_array( $details['attempted_paths'] ) ) {
					$parts[] = 'attempted_paths=' . implode( ',', array_map( 'sanitize_text_field', $details['attempted_paths'] ) );
				}

				if ( ! empty( $parts ) ) {
					$context = ' [' . implode( '; ', $parts ) . ']';
				}
			}

			$this->logger->info( sprintf( 'Enrichment fallback for %s: %s%s', $family, $bundle->get_error_message(), $context ) );
			return $font;
		}

		$metadata = $this->parse_metadata_text( $bundle['metadata_text'] );
		$copy     = $this->extract_copy( $bundle['description_html'], $bundle['article_html'] );
		$designer = ! empty( $metadata['designer'] ) ? $metadata['designer'] : ( ! empty( $font['designer'] ) ? sanitize_text_field( $font['designer'] ) : '' );
		$category = ! empty( $metadata['category'] ) ? $this->normalize_google_category( $metadata['category'] ) : ( ! empty( $font['category'] ) ? sanitize_text_field( $font['category'] ) : '' );
		$subsets  = ! empty( $metadata['subsets'] ) ? $metadata['subsets'] : ( ! empty( $font['subsets'] ) && is_array( $font['subsets'] ) ? array_map( 'sanitize_text_field', $font['subsets'] ) : array() );
		$variants = ! empty( $metadata['variants'] ) ? $metadata['variants'] : ( ! empty( $font['variants'] ) && is_array( $font['variants'] ) ? array_map( 'sanitize_text_field', $font['variants'] ) : array() );
		$enriched = array(
			'designer'            => $designer,
			'designer_list'       => $this->split_designers( $designer ),
			'foundry'             => ! empty( $font['foundry'] ) ? sanitize_text_field( $font['foundry'] ) : 'Google Fonts',
			'category'            => $category,
			'subsets'             => $subsets,
			'variants'            => $variants,
			'description_plain'   => $copy['description'],
			'article_plain'       => $copy['article'],
			'repo_directory'      => sanitize_text_field( $bundle['repo_directory'] ),
			'google_fonts_url'    => esc_url_raw( $bundle['google_fonts_url'] ),
			'metadata_url'        => esc_url_raw( $bundle['metadata_url'] ),
			'license_url'         => esc_url_raw( $bundle['license_data']['license_url'] ),
			'license_source_url'  => esc_url_raw( $bundle['license_data']['license_url'] ),
			'is_variable'         => ! empty( $metadata['axes'] ),
			'axes'                => $metadata['axes'],
			'copyrights'          => $metadata['copyrights'],
			'family_name'         => ! empty( $metadata['name'] ) ? $metadata['name'] : $family,
			'enrichment_flags'    => array(),
		);

		set_transient( $cache_key, $enriched, 12 * HOUR_IN_SECONDS );

		return array_merge( $font, $enriched );
	}

	/**
	 * Parse Google Fonts METADATA.pb text heuristically.
	 *
	 * @param string $text Metadata text.
	 * @return array<string, mixed>
	 */
	private function parse_metadata_text( $text ) {
		$result = array(
			'name'       => '',
			'designer'   => '',
			'category'   => '',
			'subsets'    => array(),
			'variants'   => array(),
			'axes'       => array(),
			'copyrights' => array(),
		);

		if ( '' === $text ) {
			return $result;
		}

		if ( preg_match( '/^\s*name:\s*"([^"]+)"/m', $text, $match ) ) {
			$result['name'] = sanitize_text_field( $match[1] );
		}

		if ( preg_match( '/^\s*designer:\s*"([^"]+)"/m', $text, $match ) ) {
			$result['designer'] = sanitize_text_field( $match[1] );
		}

		if ( preg_match( '/^\s*category:\s*"([^"]+)"/m', $text, $match ) ) {
			$result['category'] = sanitize_text_field( $match[1] );
		}

		if ( preg_match_all( '/^\s*subsets:\s*"([^"]+)"/m', $text, $matches ) ) {
			$result['subsets'] = array_values( array_unique( array_map( 'sanitize_text_field', $matches[1] ) ) );
		}

		if ( preg_match_all( '/^\s*fonts\s*\{(.*?)^\s*\}/ms', $text, $font_blocks ) ) {
			foreach ( $font_blocks[1] as $block ) {
				$style  = '';
				$weight = '';

				if ( preg_match( '/style:\s*"([^"]+)"/', $block, $style_match ) ) {
					$style = strtolower( sanitize_text_field( $style_match[1] ) );
				}

				if ( preg_match( '/weight:\s*([0-9]+)/', $block, $weight_match ) ) {
					$weight = sanitize_text_field( $weight_match[1] );
				}

				if ( preg_match( '/copyright:\s*"([^"]+)"/', $block, $copyright_match ) ) {
					$result['copyrights'][] = sanitize_text_field( $copyright_match[1] );
				}

				$result['variants'][] = $this->normalize_variant_name( $weight, $style );
			}
		}

		if ( preg_match_all( '/^\s*axes\s*\{(.*?)^\s*\}/ms', $text, $axis_blocks ) ) {
			foreach ( $axis_blocks[1] as $block ) {
				$axis = array(
					'tag'   => '',
					'min'   => '',
					'max'   => '',
				);

				if ( preg_match( '/tag:\s*"([^"]+)"/', $block, $tag_match ) ) {
					$axis['tag'] = sanitize_text_field( $tag_match[1] );
				}

				if ( preg_match( '/min_value:\s*([0-9.]+)/', $block, $min_match ) ) {
					$axis['min'] = sanitize_text_field( $min_match[1] );
				}

				if ( preg_match( '/max_value:\s*([0-9.]+)/', $block, $max_match ) ) {
					$axis['max'] = sanitize_text_field( $max_match[1] );
				}

				if ( '' !== $axis['tag'] ) {
					$result['axes'][] = $axis;
				}
			}
		}

		$result['variants']   = array_values( array_unique( array_filter( $result['variants'] ) ) );
		$result['copyrights'] = array_values( array_unique( array_filter( $result['copyrights'] ) ) );

		return $result;
	}

	/**
	 * Normalize Google category format to API style.
	 *
	 * @param string $category Raw category.
	 * @return string
	 */
	private function normalize_google_category( $category ) {
		$category = strtolower( str_replace( '_', '-', sanitize_text_field( $category ) ) );

		return $category;
	}

	/**
	 * Split a designer string into multiple names.
	 *
	 * @param string $designer Raw designer string.
	 * @return array<int, string>
	 */
	private function split_designers( $designer ) {
		$designer = sanitize_text_field( $designer );

		if ( '' === $designer ) {
			return array();
		}

		$parts = preg_split( '/\s*(?:,|&| and )\s*/i', $designer );
		$parts = is_array( $parts ) ? $parts : array( $designer );

		return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $parts ) ) ) );
	}

	/**
	 * Convert weight/style info to Google API variant names.
	 *
	 * @param string $weight Weight.
	 * @param string $style  Style.
	 * @return string
	 */
	private function normalize_variant_name( $weight, $style ) {
		$weight = sanitize_text_field( $weight );
		$style  = strtolower( sanitize_text_field( $style ) );

		if ( '' === $weight ) {
			return '';
		}

		if ( '400' === $weight && 'normal' === $style ) {
			return 'regular';
		}

		if ( '400' === $weight && 'italic' === $style ) {
			return 'italic';
		}

		if ( 'italic' === $style ) {
			return $weight . 'italic';
		}

		return $weight;
	}

	/**
	 * Convert repository HTML copy into concise plain text.
	 *
	 * @param string $description_html Description HTML.
	 * @param string $article_html     Article HTML.
	 * @return array<string, string>
	 */
	private function extract_copy( $description_html, $article_html ) {
		return array(
			'description' => $this->normalize_html_copy( $description_html ),
			'article'     => $this->normalize_html_copy( $article_html ),
		);
	}

	/**
	 * Convert HTML into a short plain-text paragraph.
	 *
	 * @param string $html Source HTML.
	 * @return string
	 */
	private function normalize_html_copy( $html ) {
		$html = (string) $html;

		if ( '' === $html ) {
			return '';
		}

		$text = wp_strip_all_tags( preg_replace( '/\s+/', ' ', $html ) );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		if ( '' === $text ) {
			return '';
		}

		return mb_substr( $text, 0, 340 );
	}
}
