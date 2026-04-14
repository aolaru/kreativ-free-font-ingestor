<?php
/**
 * Post publisher.
 *
 * @package KreativFontIngestor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KFI_Publisher {
	/**
	 * Fixed allowed style terms.
	 *
	 * @var array<int, string>
	 */
	private $style_terms = array( 'Serif', 'Sans Serif', 'Script', 'Display', 'Slab Serif', 'Monospace', 'Blackletter', 'Symbol & Dingbats', 'Variable' );

	/**
	 * Fixed allowed mood terms.
	 *
	 * @var array<int, string>
	 */
	private $mood_terms = array( 'Modern', 'Vintage', 'Elegant', 'Minimal', 'Luxury', 'Futuristic', 'Retro', 'Playful', 'Bold', 'Cute' );

	/**
	 * Fixed allowed use-case terms.
	 *
	 * @var array<int, string>
	 */
	private $use_case_terms = array( 'Logo', 'Branding', 'Wedding', 'Editorial', 'Social Media', 'Packaging', 'Poster', 'Web', 'App UI' );

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
	}

	/**
	 * Create SEO-ready post.
	 *
	 * @param array<string, mixed> $font      Font data.
	 * @param array<string, mixed> $download  Download data.
	 * @param array<string, mixed> $zip_file  ZIP data.
	 * @param array<string, mixed> $settings  Plugin settings.
	 * @return int|WP_Error
	 */
	public function create_post( array $font, array $download, array $zip_file, array $settings ) {
		$font_name = sanitize_text_field( $download['font_name'] );
		$template  = KFI_PLUGIN_DIR . 'templates/post-template.php';

		$existing_post = $this->get_existing_post_id( $font_name );

		if ( $existing_post ) {
			$this->logger->info( sprintf( 'Skipped post creation for %s because post #%d already exists.', $font_name, $existing_post ) );
			return $existing_post;
		}

		if ( ! file_exists( $template ) ) {
			return new WP_Error( 'kfi_template_missing', 'Post template file is missing.' );
		}

		require_once $template;

		$category_id = $this->ensure_category( $settings );
		$tag_ids     = $this->ensure_tags( $font_name, $font );

		$postarr = array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => sprintf( '%s Font Free Download (Commercial Use)', $font_name ),
			'post_content' => '',
			'post_excerpt' => sprintf( 'Download %s font for free with commercial use details, licensing, and local ZIP package.', $font_name ),
			'post_name'    => sanitize_title( $font_name . ' font free download' ),
		);

		$post_id = wp_insert_post( wp_slash( $postarr ), true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( $category_id ) {
			wp_set_post_categories( $post_id, array( $category_id ), true );
		}

		if ( ! empty( $tag_ids ) ) {
			wp_set_post_terms( $post_id, $tag_ids, 'post_tag', true );
		}

		$taxonomy_result = $this->assign_font_hierarchy( $post_id, $font, $settings );

		update_post_meta( $post_id, '_kfi_zip_url', esc_url_raw( $zip_file['zip_url'] ) );
		update_post_meta( $post_id, '_kfi_font_family', $font_name );
		update_post_meta( $post_id, '_kfi_license', 'SIL Open Font License 1.1' );
		update_post_meta( $post_id, '_kfi_license_source_url', esc_url_raw( $download['license_source_url'] ) );
		update_post_meta( $post_id, '_kfi_variant_count', count( $download['files'] ) );
		update_post_meta( $post_id, '_kfi_variants', wp_list_pluck( $download['files'], 'variant' ) );
		update_post_meta( $post_id, '_kfi_subsets', isset( $font['subsets'] ) && is_array( $font['subsets'] ) ? array_map( 'sanitize_text_field', $font['subsets'] ) : array() );
		update_post_meta( $post_id, '_kfi_font_category', isset( $font['category'] ) ? sanitize_text_field( $font['category'] ) : '' );
		update_post_meta( $post_id, '_kfi_zip_size', isset( $zip_file['zip_size'] ) ? (int) $zip_file['zip_size'] : 0 );
		update_post_meta( $post_id, '_kfi_zip_size_human', size_format( isset( $zip_file['zip_size'] ) ? (int) $zip_file['zip_size'] : 0, 2 ) );
		update_post_meta( $post_id, '_kfi_package_file_count', count( $download['files'] ) + 2 );
		$preview_font = $this->get_preview_font_data( $download['files'] );
		update_post_meta( $post_id, '_kfi_preview_font_url', esc_url_raw( $preview_font['url'] ) );
		update_post_meta( $post_id, '_kfi_preview_font_format', sanitize_text_field( $preview_font['format'] ) );
		update_post_meta( $post_id, '_kfi_featured_image_placeholder', 1 );
		update_post_meta( $post_id, '_kfi_taxonomy_assignment', $taxonomy_result );
		$this->refresh_post_content( $post_id, $font, $download, $zip_file, $settings );

		$this->logger->info( sprintf( 'Created post #%d for %s.', $post_id, $font_name ) );

		return $post_id;
	}

	/**
	 * Refresh generated post content using the current post state.
	 *
	 * @param int                  $post_id   Post ID.
	 * @param array<string, mixed> $font      Font data.
	 * @param array<string, mixed> $download  Download data.
	 * @param array<string, mixed> $zip_file  ZIP data.
	 * @param array<string, mixed> $settings  Plugin settings.
	 * @return int|WP_Error
	 */
	public function refresh_post_content( $post_id, array $font, array $download, array $zip_file, array $settings ) {
		$template = KFI_PLUGIN_DIR . 'templates/post-template.php';

		if ( ! file_exists( $template ) ) {
			return new WP_Error( 'kfi_template_missing', 'Post template file is missing.' );
		}

		require_once $template;

		$content = kfi_render_post_template(
			array(
				'post_id'     => absint( $post_id ),
				'font'        => $font,
				'download'    => $download,
				'zip'         => $zip_file,
				'affiliate'   => isset( $settings['affiliate_html'] ) ? wp_kses_post( $settings['affiliate_html'] ) : '',
			)
		);

		return wp_update_post(
			wp_slash(
				array(
					'ID'           => absint( $post_id ),
					'post_content' => $content,
				)
			),
			true
		);
	}

	/**
	 * Ensure required category exists.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return int
	 */
	private function ensure_category( array $settings ) {
		$existing_id = isset( $settings['category_id'] ) ? absint( $settings['category_id'] ) : 0;

		if ( $existing_id && term_exists( $existing_id, 'category' ) ) {
			return $existing_id;
		}

		$term = term_exists( 'Free Fonts', 'category' );

		if ( ! $term ) {
			$term = wp_insert_term( 'Free Fonts', 'category' );
		}

		if ( is_wp_error( $term ) ) {
			return 0;
		}

		return isset( $term['term_id'] ) ? (int) $term['term_id'] : (int) $term;
	}

	/**
	 * Ensure useful tags exist.
	 *
	 * @param string               $font_name Font name.
	 * @param array<string, mixed> $font      Font data.
	 * @return array<int, int>
	 */
	private function ensure_tags( $font_name, array $font ) {
		$tags = array(
			$font_name,
			'Free Fonts',
			'Commercial Use Fonts',
			'Google Fonts',
		);

		if ( ! empty( $font['category'] ) ) {
			$tags[] = sanitize_text_field( ucfirst( $font['category'] ) . ' Fonts' );
		}

		$tag_ids = array();

		foreach ( $tags as $tag_name ) {
			$term = term_exists( $tag_name, 'post_tag' );

			if ( ! $term ) {
				$term = wp_insert_term( $tag_name, 'post_tag' );
			}

			if ( is_wp_error( $term ) ) {
				continue;
			}

			$tag_ids[] = isset( $term['term_id'] ) ? (int) $term['term_id'] : (int) $term;
		}

		return array_filter( array_unique( $tag_ids ) );
	}

	/**
	 * Assign the configured hierarchical category structure for a font post.
	 *
	 * @param int                  $post_id   Post ID.
	 * @param array<string, mixed> $font      Font payload.
	 * @param array<string, mixed> $settings  Plugin settings.
	 * @return array<string, mixed>
	 */
	private function assign_font_hierarchy( $post_id, array $font, array $settings ) {
		$post_id = absint( $post_id );
		$assigned_category_ids = array();
		$flags = array();

		$parents = array(
			'fonts'    => $this->ensure_parent_category( $settings['taxonomy_parent_fonts'] ),
			'designer' => $this->ensure_parent_category( $settings['taxonomy_parent_designer'] ),
			'foundry'  => $this->ensure_parent_category( $settings['taxonomy_parent_foundry'] ),
			'style'    => $this->ensure_parent_category( $settings['taxonomy_parent_style'] ),
			'mood'     => $this->ensure_parent_category( $settings['taxonomy_parent_mood'] ),
			'use_case' => $this->ensure_parent_category( $settings['taxonomy_parent_use_case'] ),
		);

		if ( $parents['fonts'] ) {
			$assigned_category_ids[] = $parents['fonts'];
		}

		$designer_terms = $this->infer_designers( $font );

		if ( empty( $designer_terms ) ) {
			$flags[] = 'missing designer';
		} else {
			foreach ( $designer_terms as $designer_term ) {
				$term_id = $this->ensure_child_category( $designer_term, $parents['designer'], true );

				if ( $term_id ) {
					$assigned_category_ids[] = $term_id;
				}
			}
		}

		$foundry_term = $this->infer_foundry( $font );

		if ( empty( $foundry_term ) ) {
			$flags[] = 'missing foundry';
		} else {
			$term_id = $this->ensure_child_category( $foundry_term, $parents['foundry'], true );

			if ( $term_id ) {
				$assigned_category_ids[] = $term_id;
			}
		}

		$style_term = $this->normalize_single_vocab_term( $this->infer_style( $font ), $this->style_terms );

		if ( empty( $style_term ) ) {
			$flags[] = 'missing font style';
		} else {
			$term_id = $this->ensure_child_category( $style_term, $parents['style'], false );

			if ( $term_id ) {
				$assigned_category_ids[] = $term_id;
			} else {
				$flags[] = 'invalid style term';
			}
		}

		$mood_terms = $this->normalize_multi_vocab_terms( $this->infer_moods( $font ), $this->mood_terms );

		if ( empty( $mood_terms ) ) {
			$flags[] = 'missing font mood';
		} else {
			foreach ( $mood_terms as $mood_term ) {
				$term_id = $this->ensure_child_category( $mood_term, $parents['mood'], false );

				if ( $term_id ) {
					$assigned_category_ids[] = $term_id;
				} else {
					$flags[] = 'invalid mood term';
				}
			}
		}

		$use_case_terms = $this->normalize_multi_vocab_terms( $this->infer_use_cases( $font ), $this->use_case_terms );

		if ( empty( $use_case_terms ) ) {
			$flags[] = 'missing font use case';
		} else {
			foreach ( $use_case_terms as $use_case_term ) {
				$term_id = $this->ensure_child_category( $use_case_term, $parents['use_case'], false );

				if ( $term_id ) {
					$assigned_category_ids[] = $term_id;
				} else {
					$flags[] = 'invalid use case term';
				}
			}
		}

		$assigned_category_ids = array_filter( array_unique( array_map( 'absint', $assigned_category_ids ) ) );

		if ( ! empty( $assigned_category_ids ) ) {
			wp_set_post_categories( $post_id, $assigned_category_ids, true );
		}

		if ( ! empty( $flags ) ) {
			$this->logger->info(
				sprintf(
					'Font taxonomy flags for post #%1$d: %2$s',
					$post_id,
					implode( ', ', array_unique( $flags ) )
				)
			);
		}

		return array(
			'parents'          => $parents,
			'assigned_terms'   => $assigned_category_ids,
			'designer_terms'   => $designer_terms,
			'foundry_term'     => $foundry_term,
			'style_term'       => $style_term,
			'mood_terms'       => $mood_terms,
			'use_case_terms'   => $use_case_terms,
			'cleanup_flags'    => array_values( array_unique( $flags ) ),
		);
	}

	/**
	 * Ensure a configured parent category exists.
	 *
	 * @param string $name Parent term name.
	 * @return int
	 */
	private function ensure_parent_category( $name ) {
		$name = sanitize_text_field( $name );

		if ( '' === $name ) {
			return 0;
		}

		$term = term_exists( $name, 'category' );

		if ( ! $term ) {
			$term = wp_insert_term( $name, 'category' );
		}

		if ( is_wp_error( $term ) ) {
			return 0;
		}

		return isset( $term['term_id'] ) ? (int) $term['term_id'] : (int) $term;
	}

	/**
	 * Ensure child category exists under a parent.
	 *
	 * @param string $name         Term name.
	 * @param int    $parent_id    Parent ID.
	 * @param bool   $allow_create Whether missing terms may be created.
	 * @return int
	 */
	private function ensure_child_category( $name, $parent_id, $allow_create ) {
		$name      = sanitize_text_field( $name );
		$parent_id = absint( $parent_id );

		if ( '' === $name || ! $parent_id ) {
			return 0;
		}

		$existing = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'parent'     => $parent_id,
				'name'       => $name,
				'number'     => 1,
			)
		);

		if ( ! is_wp_error( $existing ) && ! empty( $existing[0] ) ) {
			return (int) $existing[0]->term_id;
		}

		if ( ! $allow_create ) {
			$created = wp_insert_term( $name, 'category', array( 'parent' => $parent_id ) );

			if ( is_wp_error( $created ) ) {
				return 0;
			}

			return isset( $created['term_id'] ) ? (int) $created['term_id'] : (int) $created;
		}

		$created = wp_insert_term( $name, 'category', array( 'parent' => $parent_id ) );

		if ( is_wp_error( $created ) ) {
			return 0;
		}

		return isset( $created['term_id'] ) ? (int) $created['term_id'] : (int) $created;
	}

	/**
	 * Infer designer names from available font payload.
	 *
	 * @param array<string, mixed> $font Font payload.
	 * @return array<int, string>
	 */
	private function infer_designers( array $font ) {
		if ( ! empty( $font['designer'] ) ) {
			return array( sanitize_text_field( $font['designer'] ) );
		}

		return array();
	}

	/**
	 * Infer foundry from available font payload.
	 *
	 * @param array<string, mixed> $font Font payload.
	 * @return string
	 */
	private function infer_foundry( array $font ) {
		if ( ! empty( $font['foundry'] ) ) {
			return sanitize_text_field( $font['foundry'] );
		}

		return 'Google Fonts';
	}

	/**
	 * Infer normalized style term.
	 *
	 * @param array<string, mixed> $font Font payload.
	 * @return string
	 */
	private function infer_style( array $font ) {
		$category = ! empty( $font['category'] ) ? strtolower( sanitize_text_field( $font['category'] ) ) : '';
		$name     = ! empty( $font['family'] ) ? strtolower( sanitize_text_field( $font['family'] ) ) : '';

		$map = array(
			'serif'      => 'Serif',
			'sans-serif' => 'Sans Serif',
			'display'    => 'Display',
			'handwriting'=> 'Script',
			'monospace'  => 'Monospace',
		);

		if ( isset( $map[ $category ] ) ) {
			return $map[ $category ];
		}

		if ( false !== strpos( $name, 'mono' ) ) {
			return 'Monospace';
		}

		if ( false !== strpos( $name, 'slab' ) ) {
			return 'Slab Serif';
		}

		if ( false !== strpos( $name, 'blackletter' ) ) {
			return 'Blackletter';
		}

		if ( false !== strpos( $name, 'variable' ) ) {
			return 'Variable';
		}

		return '';
	}

	/**
	 * Infer mood terms heuristically.
	 *
	 * @param array<string, mixed> $font Font payload.
	 * @return array<int, string>
	 */
	private function infer_moods( array $font ) {
		$category = ! empty( $font['category'] ) ? strtolower( sanitize_text_field( $font['category'] ) ) : '';
		$name     = ! empty( $font['family'] ) ? strtolower( sanitize_text_field( $font['family'] ) ) : '';
		$moods    = array();

		if ( in_array( $category, array( 'sans-serif', 'monospace' ), true ) ) {
			$moods[] = 'Modern';
		}

		if ( 'display' === $category ) {
			$moods[] = 'Bold';
		}

		if ( 'serif' === $category ) {
			$moods[] = 'Elegant';
		}

		if ( 'handwriting' === $category ) {
			$moods[] = 'Playful';
		}

		if ( false !== strpos( $name, 'retro' ) || false !== strpos( $name, 'vintage' ) ) {
			$moods[] = 'Retro';
			$moods[] = 'Vintage';
		}

		if ( false !== strpos( $name, 'modern' ) ) {
			$moods[] = 'Modern';
		}

		if ( false !== strpos( $name, 'lux' ) || false !== strpos( $name, 'royal' ) ) {
			$moods[] = 'Luxury';
		}

		if ( false !== strpos( $name, 'future' ) || false !== strpos( $name, 'tech' ) ) {
			$moods[] = 'Futuristic';
		}

		return array_values( array_unique( $moods ) );
	}

	/**
	 * Infer use-case terms heuristically.
	 *
	 * @param array<string, mixed> $font Font payload.
	 * @return array<int, string>
	 */
	private function infer_use_cases( array $font ) {
		$category  = ! empty( $font['category'] ) ? strtolower( sanitize_text_field( $font['category'] ) ) : '';
		$name      = ! empty( $font['family'] ) ? strtolower( sanitize_text_field( $font['family'] ) ) : '';
		$use_cases = array();

		if ( in_array( $category, array( 'sans-serif', 'monospace' ), true ) ) {
			$use_cases[] = 'Web';
			$use_cases[] = 'App UI';
		}

		if ( 'display' === $category ) {
			$use_cases[] = 'Poster';
			$use_cases[] = 'Branding';
			$use_cases[] = 'Logo';
		}

		if ( 'serif' === $category ) {
			$use_cases[] = 'Editorial';
		}

		if ( 'handwriting' === $category ) {
			$use_cases[] = 'Wedding';
			$use_cases[] = 'Social Media';
		}

		if ( false !== strpos( $name, 'poster' ) ) {
			$use_cases[] = 'Poster';
		}

		if ( false !== strpos( $name, 'pack' ) ) {
			$use_cases[] = 'Packaging';
		}

		return array_values( array_unique( $use_cases ) );
	}

	/**
	 * Normalize a single term against a fixed vocabulary.
	 *
	 * @param string               $term       Candidate term.
	 * @param array<int, string>   $vocabulary Allowed terms.
	 * @return string
	 */
	private function normalize_single_vocab_term( $term, array $vocabulary ) {
		$term = sanitize_text_field( $term );

		foreach ( $vocabulary as $allowed_term ) {
			if ( 0 === strcasecmp( $term, $allowed_term ) ) {
				return $allowed_term;
			}
		}

		return '';
	}

	/**
	 * Normalize multiple terms against a fixed vocabulary.
	 *
	 * @param array<int, string> $terms       Candidate terms.
	 * @param array<int, string> $vocabulary  Allowed terms.
	 * @return array<int, string>
	 */
	private function normalize_multi_vocab_terms( array $terms, array $vocabulary ) {
		$normalized_terms = array();

		foreach ( $terms as $term ) {
			$normalized_term = $this->normalize_single_vocab_term( $term, $vocabulary );

			if ( '' !== $normalized_term ) {
				$normalized_terms[] = $normalized_term;
			}
		}

		return array_values( array_unique( $normalized_terms ) );
	}

	/**
	 * Find an existing imported post for this family.
	 *
	 * @param string $font_name Font name.
	 * @return int
	 */
	private function get_existing_post_id( $font_name ) {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_kfi_font_family',
						'value' => $font_name,
					),
				),
			)
		);

		return ! empty( $posts[0] ) ? (int) $posts[0] : 0;
	}

	/**
	 * Pick the best preview font URL from downloaded assets.
	 *
	 * @param array<int, array<string, string>> $files Downloaded files.
	 * @return string
	 */
	private function get_preview_font_data( array $files ) {
		foreach ( $files as $file ) {
			if ( isset( $file['extension'] ) && 'woff2' === strtolower( $file['extension'] ) ) {
				return array(
					'url'    => $file['url'],
					'format' => 'woff2',
				);
			}
		}

		if ( isset( $files[0]['url'] ) ) {
			$extension = isset( $files[0]['extension'] ) ? strtolower( $files[0]['extension'] ) : '';

			return array(
				'url'    => $files[0]['url'],
				'format' => in_array( $extension, array( 'woff2', 'woff', 'truetype', 'ttf', 'otf', 'opentype' ), true ) ? $this->normalize_font_format( $extension ) : '',
			);
		}

		return array(
			'url'    => '',
			'format' => '',
		);
	}

	/**
	 * Normalize file extension to CSS font format descriptor.
	 *
	 * @param string $extension Extension.
	 * @return string
	 */
	private function normalize_font_format( $extension ) {
		$map = array(
			'ttf'      => 'truetype',
			'otf'      => 'opentype',
			'woff'     => 'woff',
			'woff2'    => 'woff2',
			'truetype' => 'truetype',
			'opentype' => 'opentype',
		);

		return isset( $map[ $extension ] ) ? $map[ $extension ] : '';
	}
}
