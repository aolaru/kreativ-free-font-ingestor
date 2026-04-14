<?php
/**
 * Post content template.
 *
 * @package KreativFontIngestor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'kfi_render_post_template' ) ) {
	/**
	 * Choose specimen samples based on the best available subset.
	 *
	 * @param array<int, string> $subsets Font subsets.
	 * @param string             $font_name Font family name.
	 * @return array<string, string>
	 */
	function kfi_get_specimen_samples( array $subsets, $font_name ) {
		$normalized = array_map( 'sanitize_text_field', $subsets );
		$subset     = 'latin';
		$priority   = array( 'adlam', 'arabic', 'hebrew', 'devanagari', 'bengali', 'greek', 'cyrillic', 'latin-ext', 'latin' );

		foreach ( $priority as $candidate ) {
			if ( in_array( $candidate, $normalized, true ) ) {
				$subset = $candidate;
				break;
			}
		}

		$samples = array(
			'latin' => array(
				'headline'   => sprintf( '%s Aa Bb Cc 123', $font_name ),
				'paragraph'  => sprintf( '%s brings a restrained rhythm that works well in interface copy, decks, simple landing pages, and clean branded layouts.', $font_name ),
				'characters' => "ABCDEFGHIJKLMNOPQRSTUVWXYZ\nabcdefghijklmnopqrstuvwxyz\n0123456789 !? @#$%",
				'logo'       => sprintf( '%s Studio', $font_name ),
				'direction'  => 'ltr',
			),
			'latin-ext' => array(
				'headline'   => sprintf( '%s ÁÉÍÓÚ ĂÂÎȘȚ', $font_name ),
				'paragraph'  => 'Crème brûlée, déjà vu, și tipografie clară pentru proiecte editoriale și digitale.',
				'characters' => "ÁĂÂÄÇÉÍÎŁÑÓÖȘȚÜŽ\náăâäçéíîłñóöșțüž\n0123456789 !? @#$%",
				'logo'       => sprintf( '%s Atelier', $font_name ),
				'direction'  => 'ltr',
			),
			'cyrillic' => array(
				'headline'   => 'АБВГД абвгд 123',
				'paragraph'  => 'Четливый образец шрифта для интерфейсов, заголовков и редакционных макетов.',
				'characters' => "АБВГДЕЖЗИЙКЛМНОП\nабвгдежзийклмноп\n0123456789 !? @#$%",
				'logo'       => 'Студия',
				'direction'  => 'ltr',
			),
			'greek' => array(
				'headline'   => 'ΑΒΓΔΕ αβγδε 123',
				'paragraph'  => 'Καθαρό δείγμα γραμματοσειράς για τίτλους, περιεχόμενο και ψηφιακά interfaces.',
				'characters' => "ΑΒΓΔΕΖΗΘΙΚΛΜΝΞΟΠ\nαβγδεζηθικλμνξοπ\n0123456789 !? @#$%",
				'logo'       => 'Στούντιο',
				'direction'  => 'ltr',
			),
			'arabic' => array(
				'headline'   => 'أبجدية عربية ١٢٣',
				'paragraph'  => 'نموذج واضح لمعاينة الخط في العناوين والواجهات والتصميمات الرقمية.',
				'characters' => "ابتثجحخدذرزسشصض\nطظعغفقكلمنهوي\n١٢٣٤٥٦٧٨٩٠",
				'logo'       => 'استوديو',
				'direction'  => 'rtl',
			),
			'hebrew' => array(
				'headline'   => 'אבגדה עברית 123',
				'paragraph'  => 'דוגמת טקסט נקייה לבדיקת הכותרות, הממשק והפריסה הטיפוגרפית.',
				'characters' => "אבגדהוזחטיכלמנס\nעפצקרשת\n0123456789",
				'logo'       => 'סטודיו',
				'direction'  => 'rtl',
			),
			'devanagari' => array(
				'headline'   => 'देवनागरी नमूना १२३',
				'paragraph'  => 'स्पष्ट फ़ॉन्ट नमूना जो शीर्षक, इंटरफ़ेस और संपादकीय लेआउट में उपयोगी है।',
				'characters' => "अआइईउऊएऐओऔकखगघ\nचछजझटठडढणतथदधन\n१२३४५६७८९०",
				'logo'       => 'स्टूडियो',
				'direction'  => 'ltr',
			),
			'bengali' => array(
				'headline'   => 'বাংলা নমুনা ১২৩',
				'paragraph'  => 'শিরোনাম, ইন্টারফেস এবং সম্পাদনামূলক লেআউটের জন্য পরিষ্কার ফন্ট নমুনা।',
				'characters' => "অআইঈউঊএঐওঔকখগঘ\nচছজঝটঠডঢণতথদধন\n১২৩৪৫৬৭৮৯০",
				'logo'       => 'স্টুডিও',
				'direction'  => 'ltr',
			),
			'adlam' => array(
				'headline'   => '𞤀𞤣𞤤𞤢𞤥 𞤁𞤢𞤤𞤮 𞥑𞥒𞥓',
				'paragraph'  => '𞤁𞤢𞤤𞤮 𞤲𞤫𞤱𞤮 𞤣𞤮𞤤 𞤺𞤫𞤲𞤺𞤮 𞤳𞤮 𞤮𞤩𞤫𞤤 𞤶𞤮𞤤𞤣𞤫 𞤫 𞤤𞤫𞤣𞤣𞤭.',
				'characters' => "𞤀𞤁𞤂𞤃𞤄𞤅𞤆𞤇𞤈𞤉𞤊𞤋𞤌𞤍\n𞤢𞤣𞤤𞤥𞤦𞤧𞤨𞤩𞤪𞤫𞤬𞤭𞤮𞤯\n𞥐𞥑𞥒𞥓𞥔𞥕𞥖𞥗𞥘𞥙",
				'logo'       => '𞤅𞤼𞤵𞤣𞤭𞤮',
				'direction'  => 'rtl',
			),
		);

		return isset( $samples[ $subset ] ) ? $samples[ $subset ] : $samples['latin'];
	}

	/**
	 * Get related imported font posts for internal linking.
	 *
	 * @param int                  $post_id Current post ID.
	 * @param array<string, mixed> $font    Font data.
	 * @return array<int, WP_Post>
	 */
	function kfi_get_related_font_posts( $post_id, array $font ) {
		$post_id   = absint( $post_id );
		$category  = ! empty( $font['category'] ) ? sanitize_text_field( $font['category'] ) : '';
		$related   = array();
		$seen_ids  = array( $post_id );
		$queries   = array();

		if ( '' !== $category ) {
			$queries[] = array(
				'meta_query' => array(
					array(
						'key'   => '_kfi_font_category',
						'value' => $category,
					),
				),
			);
		}

		$current_categories = wp_get_post_categories( $post_id );

		if ( ! empty( $current_categories ) ) {
			$queries[] = array(
				'category__in' => array_map( 'absint', $current_categories ),
			);
		}

		$queries[] = array(
			'meta_query' => array(
				array(
					'key'     => '_kfi_font_family',
					'compare' => 'EXISTS',
				),
			),
		);

		foreach ( $queries as $query_args ) {
			$posts = get_posts(
				array_merge(
					array(
						'post_type'      => 'post',
						'post_status'    => 'publish',
						'posts_per_page' => 6,
						'post__not_in'   => $seen_ids,
						'orderby'        => 'date',
						'order'          => 'DESC',
					),
					$query_args
				)
			);

			foreach ( $posts as $related_post ) {
				if ( in_array( $related_post->ID, $seen_ids, true ) ) {
					continue;
				}

				$related[]  = $related_post;
				$seen_ids[] = $related_post->ID;

				if ( count( $related ) >= 4 ) {
					break 2;
				}
			}
		}

		return $related;
	}

	/**
	 * Render post HTML.
	 *
	 * @param array<string, mixed> $data Template data.
	 * @return string
	 */
	function kfi_render_post_template( array $data ) {
		$post_id    = isset( $data['post_id'] ) ? absint( $data['post_id'] ) : 0;
		$font       = $data['font'];
		$download   = $data['download'];
		$zip        = $data['zip'];
		$affiliate   = isset( $data['affiliate'] ) ? $data['affiliate'] : '';
		$font_name   = sanitize_text_field( $download['font_name'] );
		$category    = isset( $font['category'] ) ? sanitize_text_field( $font['category'] ) : 'display';
		$subset_list = ! empty( $font['subsets'] ) && is_array( $font['subsets'] ) ? array_map( 'sanitize_text_field', $font['subsets'] ) : array( 'latin' );
		$subsets     = implode( ', ', $subset_list );
		$variants    = ! empty( $font['variants'] ) && is_array( $font['variants'] ) ? implode( ', ', array_map( 'sanitize_text_field', $font['variants'] ) ) : 'regular';
		$variant_count = count( $download['files'] );
		$zip_size      = ! empty( $zip['zip_size'] ) ? size_format( (int) $zip['zip_size'], 2 ) : '';
		$includes      = sprintf( '%d font files, OFL.txt, and metadata.json', $variant_count );
		$featured_image_side = $post_id ? get_the_post_thumbnail( $post_id, 'large', array( 'class' => 'kfi-featured-side-image' ) ) : '';
		$repo_description    = ! empty( $font['description_plain'] ) ? wp_strip_all_tags( $font['description_plain'] ) : '';
		$article_copy        = ! empty( $font['article_plain'] ) ? wp_strip_all_tags( $font['article_plain'] ) : '';
		$designer            = ! empty( $font['designer'] ) ? sanitize_text_field( $font['designer'] ) : '';
		$foundry             = ! empty( $font['foundry'] ) ? sanitize_text_field( $font['foundry'] ) : 'Google Fonts';
		$is_variable         = ! empty( $font['is_variable'] );
		$axes                = ! empty( $font['axes'] ) && is_array( $font['axes'] ) ? $font['axes'] : array();
		$axes_summary        = array();
		$google_fonts_url    = ! empty( $font['google_fonts_url'] ) ? esc_url_raw( $font['google_fonts_url'] ) : '';
		$description         = $repo_description ? $repo_description : sprintf( '%1$s is a %2$s Google Fonts family available for free download. This package preserves the original font files, includes the required OFL license, and is suitable for editorial, branding, interface, and commercial-use workflows that need clean redistribution records.', $font_name, $category );
		$use_cases           = sprintf( '%1$s works well for headings, UI labels, social graphics, landing pages, and lightweight brand systems where a reliable %2$s style is needed.', $font_name, $category );
		$pairing_copy        = sprintf( 'Pair %1$s with a neutral sans serif for product interfaces, or combine it with a higher-contrast display family when you need more hierarchy in editorial layouts.', $font_name );
		$language_copy = sprintf( 'This package includes subsets reported by Google Fonts: %s. Always verify glyph coverage against your specific production language set before launch.', $subsets );
		$samples        = kfi_get_specimen_samples( $subset_list, $font_name );
		$style_specimen = $samples['headline'];
		$body_specimen  = $samples['paragraph'];
		$number_specimen = $samples['characters'];
		$logo_specimen  = $samples['logo'];
		$text_direction = $samples['direction'];
		$show_affiliate = ! empty( trim( wp_strip_all_tags( $affiliate ) ) );
		$related_posts  = $post_id ? kfi_get_related_font_posts( $post_id, $font ) : array();
		$download_url   = $zip['zip_url'];

		if ( $post_id && class_exists( 'KFI_Plugin' ) ) {
			$download_url = KFI_Plugin::instance()->get_download_tracker()->get_download_url( $post_id, $zip['zip_url'] );
		}

		foreach ( $axes as $axis ) {
			if ( empty( $axis['tag'] ) ) {
				continue;
			}

			$axes_summary[] = sprintf(
				'%1$s %2$s-%3$s',
				sanitize_text_field( strtoupper( $axis['tag'] ) ),
				sanitize_text_field( $axis['min'] ),
				sanitize_text_field( $axis['max'] )
			);
		}

		ob_start();
		?>
		<div class="kfi-font-post">
			<section class="kfi-hero-card">
				<div class="kfi-hero-copy">
					<p class="kfi-eyebrow">Free Font Download</p>
					<h2><?php echo esc_html( $font_name ); ?> Font Package</h2>
					<p><?php echo esc_html( $description ); ?></p>
					<div class="kfi-hero-stat-grid">
						<div><strong><?php echo esc_html( ucfirst( $category ) ); ?></strong><span>Style</span></div>
						<div><strong><?php echo esc_html( (string) $variant_count ); ?></strong><span>Variants</span></div>
						<div><strong><?php echo esc_html( $zip_size ? $zip_size : 'ZIP Ready' ); ?></strong><span>Package Size</span></div>
					</div>
					<div class="kfi-inline-cta-row">
						<a class="button button-primary" href="<?php echo esc_url( $download_url ); ?>" rel="nofollow">Download <?php echo esc_html( $font_name ); ?> ZIP</a>
						<span class="kfi-inline-note">Includes original files, OFL.txt, and metadata.json</span>
					</div>
				</div>
					<div class="kfi-hero-visual">
						<?php if ( ! empty( $featured_image_side ) ) : ?>
							<div class="kfi-hero-image-shell">
								<?php echo wp_kses_post( $featured_image_side ); ?>
							</div>
						<?php endif; ?>
					</div>
				</section>

			<section class="kfi-preview-card">
				<div class="kfi-preview-header">
					<h2>Live Font Preview</h2>
					<p>Switch between specimen modes, type your own copy, and evaluate how the downloaded font feels before saving the ZIP.</p>
				</div>
					<div class="kfi-preview-presets" role="group" aria-label="Preview presets">
						<button type="button" class="kfi-preview-preset is-active" data-preview-text="<?php echo esc_attr( $style_specimen ); ?>">Headline</button>
						<button type="button" class="kfi-preview-preset" data-preview-text="<?php echo esc_attr( $body_specimen ); ?>">Paragraph</button>
						<button type="button" class="kfi-preview-preset" data-preview-text="<?php echo esc_attr( $number_specimen ); ?>">Numbers</button>
						<button type="button" class="kfi-preview-preset" data-preview-text="<?php echo esc_attr( $logo_specimen ); ?>">Logo</button>
					</div>
				<label class="kfi-control">
					<span>Preview Text</span>
					<textarea class="kfi-preview-input" rows="2"><?php echo esc_textarea( $style_specimen ); ?></textarea>
				</label>
				<div class="kfi-control-grid">
					<label class="kfi-control">
						<span>Preview Size</span>
						<input class="kfi-preview-size" type="range" min="22" max="96" step="2" value="42" />
					</label>
					<div class="kfi-preview-size-readout" aria-live="polite">42px</div>
				</div>
					<div class="kfi-preview-stage" data-font-family="<?php echo esc_attr( $font_name ); ?>" data-preview-stage dir="<?php echo esc_attr( $text_direction ); ?>">
						<p class="kfi-preview-sample"><?php echo esc_html( $style_specimen ); ?></p>
					</div>
					<div class="kfi-specimen-grid">
						<div class="kfi-specimen-card" data-font-family="<?php echo esc_attr( $font_name ); ?>" dir="<?php echo esc_attr( $text_direction ); ?>">
							<p class="kfi-specimen-label">Headline</p>
							<p class="kfi-specimen-headline"><?php echo esc_html( $font_name ); ?></p>
						</div>
						<div class="kfi-specimen-card" data-font-family="<?php echo esc_attr( $font_name ); ?>" dir="<?php echo esc_attr( $text_direction ); ?>">
							<p class="kfi-specimen-label">Paragraph</p>
							<p class="kfi-specimen-body"><?php echo esc_html( $body_specimen ); ?></p>
						</div>
						<div class="kfi-specimen-card" data-font-family="<?php echo esc_attr( $font_name ); ?>" dir="<?php echo esc_attr( $text_direction ); ?>">
							<p class="kfi-specimen-label">Characters</p>
							<?php foreach ( explode( "\n", $number_specimen ) as $character_line ) : ?>
								<p class="kfi-specimen-characters"><?php echo esc_html( $character_line ); ?></p>
							<?php endforeach; ?>
						</div>
					</div>
				<div class="kfi-info-grid">
					<div><span>Style</span><strong><?php echo esc_html( ucfirst( $category ) ); ?></strong></div>
					<div><span>Variants</span><strong><?php echo esc_html( (string) $variant_count ); ?></strong></div>
					<div><span>Subsets</span><strong><?php echo esc_html( $subsets ); ?></strong></div>
					<div><span>License</span><strong>SIL OFL 1.1</strong></div>
				</div>
			</section>

			<section class="kfi-description">
				<h2><?php echo esc_html( $font_name ); ?> Font Overview</h2>
				<p><?php echo esc_html( $description ); ?></p>
				<?php if ( $article_copy ) : ?>
					<p><?php echo esc_html( $article_copy ); ?></p>
				<?php endif; ?>
				<ul>
					<li><strong>Category:</strong> <?php echo esc_html( ucfirst( $category ) ); ?></li>
					<li><strong>Available variants:</strong> <?php echo esc_html( $variants ); ?></li>
					<li><strong>Supported subsets:</strong> <?php echo esc_html( $subsets ); ?></li>
					<?php if ( $designer ) : ?>
						<li><strong>Designer:</strong> <?php echo esc_html( $designer ); ?></li>
					<?php endif; ?>
					<li><strong>Foundry:</strong> <?php echo esc_html( $foundry ); ?></li>
					<li><strong>Variable font:</strong> <?php echo esc_html( $is_variable ? 'Yes' : 'No' ); ?></li>
					<?php if ( ! empty( $axes_summary ) ) : ?>
						<li><strong>Variable axes:</strong> <?php echo esc_html( implode( ', ', $axes_summary ) ); ?></li>
					<?php endif; ?>
				</ul>
			</section>

			<section class="kfi-copy-card kfi-editorial-block">
				<h2>How to Use <?php echo esc_html( $font_name ); ?></h2>
				<p><?php echo esc_html( $use_cases ); ?></p>
				<p><?php echo esc_html( $pairing_copy ); ?></p>
				<p><?php echo esc_html( $language_copy ); ?></p>
				<div class="kfi-note-panel">
					<strong>Commercial use note</strong>
					<p>This package is prepared for projects that need local archival records. Review the included license carefully, keep the original font naming intact, and retain the license file when redistributing the package.</p>
				</div>
			</section>

			<section class="kfi-license">
				<h2>License</h2>
				<p>This font package is distributed with the <strong>SIL Open Font License 1.1</strong>. The ZIP archive includes the required <code>OFL.txt</code> file without modification.</p>
				<p><a href="<?php echo esc_url( $download['license_url'] ); ?>">View OFL.txt</a></p>
			</section>

			<section class="kfi-package-files">
				<h2>Package Details</h2>
				<div class="kfi-info-grid">
					<div><span>Archive file</span><strong><?php echo esc_html( $zip['zip_name'] ); ?></strong></div>
					<div><span>Packaged assets</span><strong><?php echo esc_html( (string) ( $variant_count + 2 ) ); ?></strong></div>
					<div><span>ZIP size</span><strong><?php echo esc_html( $zip_size ? $zip_size : 'Available after ZIP generation' ); ?></strong></div>
					<div><span>Local records</span><strong>Fonts, OFL.txt, metadata.json</strong></div>
					<?php if ( $google_fonts_url ) : ?>
						<div><span>Source family</span><strong><a href="<?php echo esc_url( $google_fonts_url ); ?>" rel="nofollow noopener" target="_blank">View on Google Fonts</a></strong></div>
					<?php endif; ?>
				</div>
			</section>

			<section class="kfi-bottom-cta">
				<div>
					<h2>Download <?php echo esc_html( $font_name ); ?> Now</h2>
					<p>Save the local ZIP package for design testing, client mockups, or production asset archiving.</p>
				</div>
				<p class="kfi-download-button">
					<a class="button button-primary" href="<?php echo esc_url( $download_url ); ?>" rel="nofollow">Download ZIP Package</a>
				</p>
			</section>

			<section class="kfi-faq">
				<h2><?php echo esc_html( $font_name ); ?> Font FAQ</h2>
				<h3>Is <?php echo esc_html( $font_name ); ?> free for commercial use?</h3>
				<p>This package is distributed under the SIL Open Font License 1.1. Commercial usage is generally allowed under that license, but your team should still review the included OFL text before redistribution or modification.</p>
				<h3>What is included in the download?</h3>
				<p>The ZIP includes locally stored font files, the required <code>OFL.txt</code> license file, and <code>metadata.json</code> for internal indexing.</p>
				<h3>Why download a local package instead of linking to Google Fonts directly?</h3>
				<p>A local package is useful when you need archive-ready assets, offline review, CMS uploads, or a distribution workflow that keeps the license alongside the font files.</p>
			</section>

			<?php if ( ! empty( $related_posts ) ) : ?>
				<section class="kfi-related-fonts">
					<h2>Explore Related Fonts</h2>
					<div class="kfi-specimen-grid">
						<?php foreach ( $related_posts as $related_post ) : ?>
							<a class="kfi-specimen-card kfi-related-card" href="<?php echo esc_url( get_permalink( $related_post ) ); ?>">
								<p class="kfi-specimen-label"><?php echo esc_html( get_post_meta( $related_post->ID, '_kfi_font_category', true ) ? ucfirst( get_post_meta( $related_post->ID, '_kfi_font_category', true ) ) : 'Font' ); ?></p>
								<p class="kfi-specimen-headline"><?php echo esc_html( get_post_meta( $related_post->ID, '_kfi_font_family', true ) ? get_post_meta( $related_post->ID, '_kfi_font_family', true ) : get_the_title( $related_post ) ); ?></p>
								<p class="kfi-specimen-body"><?php echo esc_html( get_post_meta( $related_post->ID, '_kfi_font_description', true ) ? wp_trim_words( get_post_meta( $related_post->ID, '_kfi_font_description', true ), 18 ) : get_the_title( $related_post ) ); ?></p>
							</a>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

			<?php if ( $show_affiliate ) : ?>
				<section class="kfi-affiliate">
					<h2>Recommended Design Resources</h2>
					<?php echo wp_kses_post( $affiliate ); ?>
				</section>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
