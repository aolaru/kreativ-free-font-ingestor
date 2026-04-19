<?php
/**
 * Featured image generator.
 *
 * @package KreativFontIngestor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KFI_Featured_Image {
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
	 * Generate and assign a featured image to the imported post.
	 *
	 * @param int                  $post_id   Post ID.
	 * @param array<string, mixed> $download  Download data.
	 * @param array<string, mixed> $font      Font data.
	 * @param bool                 $force     Force regeneration.
	 * @return int|WP_Error Attachment ID or error.
	 */
	public function generate_for_post( $post_id, array $download, array $font, $force = false ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return new WP_Error( 'kfi_featured_invalid_post', 'Invalid post ID for featured image generation.' );
		}

		$existing_thumbnail = get_post_thumbnail_id( $post_id );

		if ( $existing_thumbnail && ! $force ) {
			return (int) $existing_thumbnail;
		}

		if ( $existing_thumbnail && $force ) {
			wp_delete_attachment( $existing_thumbnail, true );
		}

		$paths       = $this->logger->get_upload_paths();
		$preview_dir = trailingslashit( $paths['base_dir'] ) . 'previews/';
		$preview_url = trailingslashit( $paths['base_url'] ) . 'previews/';
		$font_name   = sanitize_text_field( $download['font_name'] );
		$font_slug   = sanitize_title( $font_name );
		$image_path  = $preview_dir . $font_slug . '-featured.png';
		$image_url   = $preview_url . $font_slug . '-featured.png';
		$font_path   = $this->get_renderable_font_path( $download['files'] );

		wp_mkdir_p( $preview_dir );
		$this->ensure_preview_index( $preview_dir );

		$generated = $this->render_image(
			$image_path,
			array(
				'font_name' => $font_name,
				'category'  => isset( $font['category'] ) ? sanitize_text_field( $font['category'] ) : 'Sans Serif',
				'font_path' => $font_path,
				'subsets'   => isset( $font['subsets'] ) && is_array( $font['subsets'] ) ? array_map( 'sanitize_text_field', $font['subsets'] ) : array( 'latin' ),
			)
		);

		if ( is_wp_error( $generated ) ) {
			return $generated;
		}

		$attachment_id = $this->attach_image_to_post( $post_id, $image_path, $image_url, $font_name );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		set_post_thumbnail( $post_id, $attachment_id );
		update_post_meta( $post_id, '_kfi_featured_image_placeholder', 0 );

		$specimen = $this->generate_specimen_for_post( $post_id, $download, $font, $force );

		if ( is_wp_error( $specimen ) ) {
			$this->logger->error( sprintf( 'Generated featured image for post #%d, but specimen image failed: %s', $post_id, $specimen->get_error_message() ) );
		}

		$this->logger->info( sprintf( 'Generated featured image for post #%d.', $post_id ) );

		return $attachment_id;
	}

	/**
	 * Generate and store a specimen image for the imported post.
	 *
	 * @param int                  $post_id  Post ID.
	 * @param array<string, mixed> $download Download data.
	 * @param array<string, mixed> $font     Font data.
	 * @param bool                 $force    Force regeneration.
	 * @return array<string, string>|WP_Error
	 */
	public function generate_specimen_for_post( $post_id, array $download, array $font, $force = false ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return new WP_Error( 'kfi_specimen_invalid_post', 'Invalid post ID for specimen image generation.' );
		}

		$paths        = $this->logger->get_upload_paths();
		$preview_dir  = trailingslashit( $paths['base_dir'] ) . 'previews/';
		$preview_url  = trailingslashit( $paths['base_url'] ) . 'previews/';
		$font_name    = sanitize_text_field( $download['font_name'] );
		$font_slug    = sanitize_title( $font_name );
		$image_path   = $preview_dir . $font_slug . '-specimen.png';
		$image_url    = $preview_url . $font_slug . '-specimen.png';
		$font_path    = $this->get_renderable_font_path( $download['files'] );

		if ( file_exists( $image_path ) && ! $force ) {
			update_post_meta( $post_id, '_kfi_specimen_image_url', esc_url_raw( $image_url ) );
			update_post_meta( $post_id, '_kfi_specimen_image_path', sanitize_text_field( $image_path ) );

			return array(
				'url'  => $image_url,
				'path' => $image_path,
			);
		}

		wp_mkdir_p( $preview_dir );
		$this->ensure_preview_index( $preview_dir );

		$generated = $this->render_specimen_image(
			$image_path,
			array(
				'font_name' => $font_name,
				'category'  => isset( $font['category'] ) ? sanitize_text_field( $font['category'] ) : 'Sans Serif',
				'font_path' => $font_path,
				'subsets'   => isset( $font['subsets'] ) && is_array( $font['subsets'] ) ? array_map( 'sanitize_text_field', $font['subsets'] ) : array( 'latin' ),
			)
		);

		if ( is_wp_error( $generated ) ) {
			return $generated;
		}

		update_post_meta( $post_id, '_kfi_specimen_image_url', esc_url_raw( $image_url ) );
		update_post_meta( $post_id, '_kfi_specimen_image_path', sanitize_text_field( $image_path ) );

		return array(
			'url'  => $image_url,
			'path' => $image_path,
		);
	}

	/**
	 * Render a branded PNG using GD.
	 *
	 * @param string               $target_path Output path.
	 * @param array<string,string> $data        Render data.
	 * @return true|WP_Error
	 */
	private function render_image( $target_path, array $data ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return new WP_Error( 'kfi_featured_no_gd', 'GD extension is required to generate featured images.' );
		}

		$width        = 1600;
		$height       = 900;
		$scale        = 2;
		$canvas_width = $width * $scale;
		$canvas_height = $height * $scale;
		$image        = $this->create_canvas( $canvas_width, $canvas_height );

		if ( ! $image ) {
			return new WP_Error( 'kfi_featured_create_failed', 'Unable to create image canvas.' );
		}

		imageantialias( $image, true );

		$theme      = $this->get_category_theme( $data['category'] );
		$bg_start   = $theme['bg_start'];
		$bg_end     = $theme['bg_end'];
		$accent     = imagecolorallocate( $image, $theme['accent'][0], $theme['accent'][1], $theme['accent'][2] );
		$deep       = imagecolorallocate( $image, $theme['deep'][0], $theme['deep'][1], $theme['deep'][2] );
		$soft_dark  = imagecolorallocate( $image, $theme['soft_dark'][0], $theme['soft_dark'][1], $theme['soft_dark'][2] );
		$shape_a    = imagecolorallocatealpha( $image, $theme['shape_a'][0], $theme['shape_a'][1], $theme['shape_a'][2], $theme['shape_a_alpha'] );
		$shape_b    = imagecolorallocatealpha( $image, $theme['shape_b'][0], $theme['shape_b'][1], $theme['shape_b'][2], $theme['shape_b_alpha'] );
		$white      = imagecolorallocate( $image, 255, 255, 255 );
		$panel_fill = imagecolorallocatealpha( $image, 255, 255, 255, 18 );
		$line_color = imagecolorallocatealpha( $image, $theme['line'][0], $theme['line'][1], $theme['line'][2], 44 );
		$samples    = $this->get_specimen_samples( $data['subsets'], $data['font_name'] );

		for ( $y = 0; $y < $canvas_height; $y++ ) {
			$ratio = $y / max( 1, $canvas_height - 1 );
			$red   = (int) round( $bg_start[0] + ( $bg_end[0] - $bg_start[0] ) * $ratio );
			$green = (int) round( $bg_start[1] + ( $bg_end[1] - $bg_start[1] ) * $ratio );
			$blue  = (int) round( $bg_start[2] + ( $bg_end[2] - $bg_start[2] ) * $ratio );
			$line  = imagecolorallocate( $image, $red, $green, $blue );
			imageline( $image, 0, $y, $canvas_width, $y, $line );
		}

		imagefilledellipse( $image, $this->scale_value( 1250, $scale ), $this->scale_value( 120, $scale ), $this->scale_value( $theme['hero_shape_w'], $scale ), $this->scale_value( $theme['hero_shape_h'], $scale ), $shape_a );
		imagefilledellipse( $image, $this->scale_value( 220, $scale ), $this->scale_value( 760, $scale ), $this->scale_value( $theme['secondary_shape_w'], $scale ), $this->scale_value( $theme['secondary_shape_h'], $scale ), $shape_b );
		imagefilledrectangle( $image, $this->scale_value( 84, $scale ), $this->scale_value( 92, $scale ), $this->scale_value( 1516, $scale ), $this->scale_value( 808, $scale ), $panel_fill );

		imagesetthickness( $image, $this->scale_value( 3, $scale ) );
		imagerectangle( $image, $this->scale_value( 84, $scale ), $this->scale_value( 92, $scale ), $this->scale_value( 1516, $scale ), $this->scale_value( 808, $scale ), $line_color );
		imagesetthickness( $image, 1 );

		$this->draw_text(
			$image,
			array(
				'text'      => 'KREATIV FONT',
				'x'         => $this->scale_value( 120, $scale ),
				'y'         => $this->scale_value( 148, $scale ),
				'size'      => $this->scale_value( 18, $scale ),
				'color'     => $accent,
				'font_path' => '',
				'builtin'   => 5,
			)
		);

		$this->draw_text(
			$image,
			array(
				'text'      => $theme['kicker'],
				'x'         => $this->scale_value( 120, $scale ),
				'y'         => $this->scale_value( 214, $scale ),
				'size'      => $this->scale_value( 24, $scale ),
				'color'     => $soft_dark,
				'font_path' => '',
				'builtin'   => 5,
			)
		);

		$this->draw_text(
			$image,
			array(
				'text'      => $data['font_name'],
				'x'         => $this->scale_value( 120, $scale ),
				'y'         => $this->scale_value( 336, $scale ),
				'size'      => $this->scale_value( max( 72, min( 108, $theme['title_size'] - 26 ) ), $scale ),
				'color'     => $deep,
				'font_path' => $data['font_path'],
				'builtin'   => 5,
			)
		);

		$this->draw_text(
			$image,
			array(
				'text'      => 'Free Download',
				'x'         => $this->scale_value( 120, $scale ),
				'y'         => $this->scale_value( 430, $scale ),
				'size'      => $this->scale_value( 34, $scale ),
				'color'     => $soft_dark,
				'font_path' => '',
				'builtin'   => 5,
			)
		);

		imagefilledroundedrectangle( $image, $this->scale_value( 938, $scale ), $this->scale_value( 208, $scale ), $this->scale_value( 1452, $scale ), $this->scale_value( 560, $scale ), $this->scale_value( 26, $scale ), imagecolorallocatealpha( $image, 255, 255, 255, 28 ) );
		imagefilledroundedrectangle( $image, $this->scale_value( 980, $scale ), $this->scale_value( 246, $scale ), $this->scale_value( 1410, $scale ), $this->scale_value( 372, $scale ), $this->scale_value( 18, $scale ), $white );
		$this->draw_text(
			$image,
			array(
				'text'      => $samples['headline'],
				'x'         => $this->scale_value( 1012, $scale ),
				'y'         => $this->scale_value( 324, $scale ),
				'size'      => $this->scale_value( 44, $scale ),
				'color'     => $deep,
				'font_path' => $data['font_path'],
				'builtin'   => 5,
			)
		);
		$this->draw_text(
			$image,
			array(
				'text'      => sprintf( '%s specimen', ucfirst( $data['category'] ) ),
				'x'         => $this->scale_value( 1012, $scale ),
				'y'         => $this->scale_value( 424, $scale ),
				'size'      => $this->scale_value( 22, $scale ),
				'color'     => $accent,
				'font_path' => '',
				'builtin'   => 4,
			)
		);
		$this->draw_text(
			$image,
			array(
				'text'      => sanitize_text_field( $samples['characters_line'] ),
				'x'         => $this->scale_value( 1012, $scale ),
				'y'         => $this->scale_value( 484, $scale ),
				'size'      => $this->scale_value( 24, $scale ),
				'color'     => $soft_dark,
				'font_path' => $data['font_path'],
				'builtin'   => 4,
			)
		);

		imagefilledroundedrectangle( $image, $this->scale_value( 1040, $scale ), $this->scale_value( 650, $scale ), $this->scale_value( 1450, $scale ), $this->scale_value( 736, $scale ), $this->scale_value( 22, $scale ), $accent );
		$this->draw_text(
			$image,
			array(
				'text'      => 'Commercial Use',
				'x'         => $this->scale_value( 1142, $scale ),
				'y'         => $this->scale_value( 706, $scale ),
				'size'      => $this->scale_value( 26, $scale ),
				'color'     => $white,
				'font_path' => '',
				'builtin'   => 5,
			)
		);

		imagefilledellipse( $image, $this->scale_value( 1378, $scale ), $this->scale_value( 230, $scale ), $this->scale_value( 134, $scale ), $this->scale_value( 134, $scale ), $shape_b );
		imagefilledellipse( $image, $this->scale_value( 1378, $scale ), $this->scale_value( 230, $scale ), $this->scale_value( 92, $scale ), $this->scale_value( 92, $scale ), $white );
		$this->draw_text(
			$image,
			array(
				'text'      => $samples['badge'],
				'x'         => $this->scale_value( 1342, $scale ),
				'y'         => $this->scale_value( 254, $scale ),
				'size'      => $this->scale_value( 42, $scale ),
				'color'     => $deep,
				'font_path' => $data['font_path'],
				'builtin'   => 5,
			)
		);

		return $this->finalize_image( $image, $target_path, $width, $height, 'kfi_featured_write_failed', 'Unable to write generated featured image.' );
	}

	/**
	 * Render a static character specimen image.
	 *
	 * @param string               $target_path Output path.
	 * @param array<string,string> $data        Render data.
	 * @return true|WP_Error
	 */
	private function render_specimen_image( $target_path, array $data ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return new WP_Error( 'kfi_specimen_no_gd', 'GD extension is required to generate specimen images.' );
		}

		$width    = 1800;
		$height   = 1080;
		$scale    = 2;
		$canvas_width = $width * $scale;
		$canvas_height = $height * $scale;
		$image    = $this->create_canvas( $canvas_width, $canvas_height );
		$theme   = $this->get_category_theme( $data['category'] );
		$samples = $this->get_specimen_samples( $data['subsets'], $data['font_name'] );

		if ( ! $image ) {
			return new WP_Error( 'kfi_specimen_create_failed', 'Unable to create specimen image canvas.' );
		}

		imageantialias( $image, true );

		for ( $y = 0; $y < $canvas_height; $y++ ) {
			$ratio = $y / max( 1, $canvas_height - 1 );
			$red   = (int) round( $theme['bg_start'][0] + ( $theme['bg_end'][0] - $theme['bg_start'][0] ) * $ratio );
			$green = (int) round( $theme['bg_start'][1] + ( $theme['bg_end'][1] - $theme['bg_start'][1] ) * $ratio );
			$blue  = (int) round( $theme['bg_start'][2] + ( $theme['bg_end'][2] - $theme['bg_start'][2] ) * $ratio );
			$line  = imagecolorallocate( $image, $red, $green, $blue );
			imageline( $image, 0, $y, $canvas_width, $y, $line );
		}

		$deep      = imagecolorallocate( $image, $theme['deep'][0], $theme['deep'][1], $theme['deep'][2] );
		$soft_dark = imagecolorallocate( $image, $theme['soft_dark'][0], $theme['soft_dark'][1], $theme['soft_dark'][2] );
		$accent    = imagecolorallocate( $image, $theme['accent'][0], $theme['accent'][1], $theme['accent'][2] );
		$panel     = imagecolorallocatealpha( $image, 255, 255, 255, 22 );
		$line      = imagecolorallocatealpha( $image, $theme['line'][0], $theme['line'][1], $theme['line'][2], 44 );

		imagefilledellipse( $image, $this->scale_value( 1510, $scale ), $this->scale_value( 130, $scale ), $this->scale_value( 500, $scale ), $this->scale_value( 500, $scale ), imagecolorallocatealpha( $image, $theme['shape_a'][0], $theme['shape_a'][1], $theme['shape_a'][2], $theme['shape_a_alpha'] ) );
		imagefilledellipse( $image, $this->scale_value( 260, $scale ), $this->scale_value( 950, $scale ), $this->scale_value( 380, $scale ), $this->scale_value( 380, $scale ), imagecolorallocatealpha( $image, $theme['shape_b'][0], $theme['shape_b'][1], $theme['shape_b'][2], $theme['shape_b_alpha'] ) );

		imagefilledroundedrectangle( $image, $this->scale_value( 74, $scale ), $this->scale_value( 76, $scale ), $this->scale_value( 1726, $scale ), $this->scale_value( 1004, $scale ), $this->scale_value( 32, $scale ), $panel );
		imagerectangle( $image, $this->scale_value( 74, $scale ), $this->scale_value( 76, $scale ), $this->scale_value( 1726, $scale ), $this->scale_value( 1004, $scale ), $line );

		$this->draw_text(
			$image,
			array(
				'text'      => 'KREATIV FONT',
				'x'         => $this->scale_value( 120, $scale ),
				'y'         => $this->scale_value( 160, $scale ),
				'size'      => $this->scale_value( 24, $scale ),
				'color'     => $accent,
				'font_path' => '',
				'builtin'   => 5,
			)
		);

		$this->draw_text(
			$image,
			array(
				'text'      => sprintf( '%s Character Specimen', $data['font_name'] ),
				'x'         => $this->scale_value( 120, $scale ),
				'y'         => $this->scale_value( 228, $scale ),
				'size'      => $this->scale_value( 34, $scale ),
				'color'     => $deep,
				'font_path' => '',
				'builtin'   => 5,
			)
		);

		imagefilledroundedrectangle( $image, $this->scale_value( 120, $scale ), $this->scale_value( 278, $scale ), $this->scale_value( 1680, $scale ), $this->scale_value( 952, $scale ), $this->scale_value( 28, $scale ), imagecolorallocatealpha( $image, 255, 255, 255, 16 ) );

		$headline = sanitize_text_field( $samples['headline'] );
		$lines    = array_filter( array_map( 'trim', explode( "\n", (string) $samples['characters'] ) ) );

		$labels = array(
			'Uppercase',
			'Lowercase',
			'Numbers & Symbols',
			'Diacritics',
		);

		$this->draw_text(
			$image,
			array(
				'text'      => $headline,
				'x'         => $this->scale_value( 160, $scale ),
				'y'         => $this->scale_value( 420, $scale ),
				'size'      => $this->scale_value( 52, $scale ),
				'color'     => $deep,
				'font_path' => $data['font_path'],
				'builtin'   => 5,
			)
		);

		$current_y = $this->scale_value( 520, $scale );

		foreach ( array_slice( $lines, 0, 4 ) as $index => $line_text ) {
			$this->draw_text(
				$image,
				array(
					'text'      => isset( $labels[ $index ] ) ? $labels[ $index ] : '',
					'x'         => $this->scale_value( 160, $scale ),
					'y'         => $current_y,
					'size'      => $this->scale_value( 18, $scale ),
					'color'     => $accent,
					'font_path' => '',
					'builtin'   => 4,
				)
			);

			$this->draw_text(
				$image,
				array(
					'text'      => sanitize_text_field( $line_text ),
					'x'         => $this->scale_value( 160, $scale ),
					'y'         => $current_y + $this->scale_value( 46, $scale ),
					'size'      => $this->scale_value( 36, $scale ),
					'color'     => $soft_dark,
					'font_path' => $data['font_path'],
					'builtin'   => 4,
				)
			);

			$current_y += $this->scale_value( 126, $scale );
		}

		return $this->finalize_image( $image, $target_path, $width, $height, 'kfi_specimen_write_failed', 'Unable to write specimen image.' );
	}

	/**
	 * Create a high-quality GD canvas with alpha preservation.
	 *
	 * @param int $width  Canvas width.
	 * @param int $height Canvas height.
	 * @return resource|false
	 */
	private function create_canvas( $width, $height ) {
		$canvas = imagecreatetruecolor( $width, $height );

		if ( ! $canvas ) {
			return false;
		}

		imagealphablending( $canvas, true );
		imagesavealpha( $canvas, true );

		return $canvas;
	}

	/**
	 * Downsample and write a PNG image with optional sharpening.
	 *
	 * @param resource $source        Source image.
	 * @param string   $target_path   Output file.
	 * @param int      $target_width  Final width.
	 * @param int      $target_height Final height.
	 * @param string   $error_code    WP_Error code.
	 * @param string   $error_message WP_Error message.
	 * @return true|WP_Error
	 */
	private function finalize_image( $source, $target_path, $target_width, $target_height, $error_code, $error_message ) {
		$final = $this->create_canvas( $target_width, $target_height );

		if ( ! $final ) {
			imagedestroy( $source );
			return new WP_Error( $error_code, $error_message );
		}

		imagecopyresampled(
			$final,
			$source,
			0,
			0,
			0,
			0,
			$target_width,
			$target_height,
			imagesx( $source ),
			imagesy( $source )
		);

		if ( function_exists( 'imageconvolution' ) ) {
			@imageconvolution(
				$final,
				array(
					array( -1, -1, -1 ),
					array( -1, 16, -1 ),
					array( -1, -1, -1 ),
				),
				8,
				0
			);
		}

		$result = imagepng( $final, $target_path, 6 );
		imagedestroy( $source );
		imagedestroy( $final );

		if ( ! $result ) {
			return new WP_Error( $error_code, $error_message );
		}

		return true;
	}

	/**
	 * Scale a numeric layout value for supersampled rendering.
	 *
	 * @param int|float $value Value.
	 * @param int       $scale Scale factor.
	 * @return int
	 */
	private function scale_value( $value, $scale ) {
		return (int) round( $value * $scale );
	}

	/**
	 * Resolve visual theme by font category.
	 *
	 * @param string $category Font category.
	 * @return array<string, mixed>
	 */
	private function get_category_theme( $category ) {
		$category = strtolower( sanitize_text_field( $category ) );

		$themes = array(
			'display' => array(
				'layout'            => 'poster',
				'kicker'            => 'Bold display specimen',
				'bg_start'          => array( 255, 241, 232 ),
				'bg_end'            => array( 255, 252, 247 ),
				'accent'            => array( 236, 85, 52 ),
				'deep'              => array( 47, 24, 19 ),
				'soft_dark'         => array( 118, 76, 64 ),
				'shape_a'           => array( 245, 110, 66 ),
				'shape_a_alpha'     => 92,
				'shape_b'           => array( 255, 210, 84 ),
				'shape_b_alpha'     => 96,
				'line'              => array( 239, 177, 151 ),
				'hero_shape_w'      => 640,
				'hero_shape_h'      => 640,
				'secondary_shape_w' => 420,
				'secondary_shape_h' => 420,
				'title_y'           => 340,
				'title_size'        => 104,
				'subtitle_y'        => 442,
				'body_y'            => 528,
				'specimen_label_y'  => 630,
				'specimen_y'        => 760,
				'specimen_size'     => 66,
			),
			'serif' => array(
				'layout'            => 'editorial',
				'kicker'            => 'Editorial serif specimen',
				'bg_start'          => array( 242, 236, 228 ),
				'bg_end'            => array( 252, 249, 244 ),
				'accent'            => array( 145, 92, 52 ),
				'deep'              => array( 44, 31, 24 ),
				'soft_dark'         => array( 110, 91, 80 ),
				'shape_a'           => array( 182, 134, 95 ),
				'shape_a_alpha'     => 96,
				'shape_b'           => array( 225, 204, 177 ),
				'shape_b_alpha'     => 98,
				'line'              => array( 203, 182, 159 ),
				'hero_shape_w'      => 520,
				'hero_shape_h'      => 520,
				'secondary_shape_w' => 360,
				'secondary_shape_h' => 360,
				'title_y'           => 332,
				'title_size'        => 86,
				'subtitle_y'        => 430,
				'body_y'            => 512,
				'specimen_label_y'  => 622,
				'specimen_y'        => 720,
				'specimen_size'     => 52,
			),
			'sans-serif' => array(
				'layout'            => 'interface',
				'kicker'            => 'Clean interface specimen',
				'bg_start'          => array( 238, 247, 249 ),
				'bg_end'            => array( 251, 254, 255 ),
				'accent'            => array( 38, 126, 162 ),
				'deep'              => array( 22, 43, 55 ),
				'soft_dark'         => array( 78, 103, 118 ),
				'shape_a'           => array( 74, 172, 201 ),
				'shape_a_alpha'     => 96,
				'shape_b'           => array( 184, 235, 226 ),
				'shape_b_alpha'     => 98,
				'line'              => array( 176, 214, 221 ),
				'hero_shape_w'      => 560,
				'hero_shape_h'      => 560,
				'secondary_shape_w' => 430,
				'secondary_shape_h' => 430,
				'title_y'           => 320,
				'title_size'        => 96,
				'subtitle_y'        => 414,
				'body_y'            => 494,
				'specimen_label_y'  => 604,
				'specimen_y'        => 716,
				'specimen_size'     => 54,
			),
			'monospace' => array(
				'layout'            => 'interface',
				'kicker'            => 'Monospace system specimen',
				'bg_start'          => array( 236, 244, 236 ),
				'bg_end'            => array( 248, 253, 248 ),
				'accent'            => array( 43, 133, 90 ),
				'deep'              => array( 19, 48, 34 ),
				'soft_dark'         => array( 83, 113, 98 ),
				'shape_a'           => array( 74, 183, 119 ),
				'shape_a_alpha'     => 96,
				'shape_b'           => array( 183, 229, 195 ),
				'shape_b_alpha'     => 98,
				'line'              => array( 180, 214, 191 ),
				'hero_shape_w'      => 540,
				'hero_shape_h'      => 540,
				'secondary_shape_w' => 390,
				'secondary_shape_h' => 390,
				'title_y'           => 322,
				'title_size'        => 90,
				'subtitle_y'        => 414,
				'body_y'            => 496,
				'specimen_label_y'  => 602,
				'specimen_y'        => 710,
				'specimen_size'     => 52,
			),
		);

		if ( isset( $themes[ $category ] ) ) {
			return $themes[ $category ];
		}

		return array(
			'layout'            => 'poster',
			'kicker'            => 'Signature font specimen',
			'bg_start'          => array( 245, 241, 255 ),
			'bg_end'            => array( 255, 255, 255 ),
			'accent'            => array( 111, 70, 255 ),
			'deep'              => array( 34, 27, 64 ),
			'soft_dark'         => array( 89, 83, 118 ),
			'shape_a'           => array( 143, 109, 255 ),
			'shape_a_alpha'     => 92,
			'shape_b'           => array( 142, 232, 188 ),
			'shape_b_alpha'     => 104,
			'line'              => array( 220, 214, 246 ),
			'hero_shape_w'      => 560,
			'hero_shape_h'      => 560,
			'secondary_shape_w' => 420,
			'secondary_shape_h' => 420,
			'title_y'           => 320,
			'title_size'        => 92,
			'subtitle_y'        => 405,
			'body_y'            => 500,
			'specimen_label_y'  => 620,
			'specimen_y'        => 720,
			'specimen_size'     => 46,
		);
	}

	/**
	 * Choose subset-aware specimen text for featured image generation.
	 *
	 * @param array<int, string> $subsets   Supported subsets.
	 * @param string             $font_name Font family name.
	 * @return array<string, string>
	 */
	private function get_specimen_samples( array $subsets, $font_name ) {
		$subset   = 'latin';
		$priority = array( 'adlam', 'arabic', 'hebrew', 'devanagari', 'bengali', 'greek', 'cyrillic', 'latin-ext', 'latin' );

		foreach ( $priority as $candidate ) {
			if ( in_array( $candidate, $subsets, true ) ) {
				$subset = $candidate;
				break;
			}
		}

		$samples = array(
			'latin' => array(
				'headline'   => sprintf( '%s Aa Bb 123', $font_name ),
				'characters' => "ABCDEFGHIJKLMNOPQRSTUVWXYZ\nabcdefghijklmnopqrstuvwxyz\n0123456789 !? @#$%\nÁĂÂÄÇÉÍÎŁÑÓÖȘȚÜŽ áăâäçéíîłñóöșțüž",
				'badge'      => 'Aa',
			),
			'latin-ext' => array(
				'headline'   => sprintf( '%s ÁĂÂȘȚ 123', $font_name ),
				'characters' => "ABCDEFGHIJKLMNOPQRSTUVWXYZ\nabcdefghijklmnopqrstuvwxyz\n0123456789 !? @#$%\nÁĂÂÄÇÉÍÎŁÑÓÖȘȚÜŽ áăâäçéíîłñóöșțüž",
				'badge'      => 'Áă',
			),
			'cyrillic' => array(
				'headline'   => 'АБВГД абвгд 123',
				'characters' => "АБВГДЕЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ\nабвгдежзийклмнопрстуфхцчшщъыьэюя\n0123456789 !? @#$%\nЀЁЃЄЇЈЉЊЋЌЎЏ ѐёѓєїјљњћќўџ",
				'badge'      => 'Бб',
			),
			'greek' => array(
				'headline'   => 'ΑΒΓΔΕ αβγδε 123',
				'characters' => "ΑΒΓΔΕΖΗΘΙΚΛΜΝΞΟΠΡΣΤΥΦΧΨΩ\nαβγδεζηθικλμνξοπρστυφχψω\n0123456789 !? @#$%\nΆΈΉΊΌΎΏ άέήίόύώϊϋΐΰ",
				'badge'      => 'Αα',
			),
			'arabic' => array(
				'headline'   => 'أبجدية عربية ١٢٣',
				'characters' => "ابتثجحخدذرزسشصضطظعغفقكلمنهوي\nأإآؤئ ة ى ي\n١٢٣٤٥٦٧٨٩٠",
				'badge'      => 'اب',
			),
			'hebrew' => array(
				'headline'   => 'אבגדה עברית 123',
				'characters' => "אבגדהוזחטיכלמנסעפצקרשת\nךםןףץ\n0123456789",
				'badge'      => 'אב',
			),
			'devanagari' => array(
				'headline'   => 'देवनागरी नमूना १२३',
				'characters' => "अआइईउऊएऐओऔकखगघचछजझ\nटठडढणतथदधनपफबभमयरलवशषसह\n१२३४५६७८९०",
				'badge'      => 'कअ',
			),
			'bengali' => array(
				'headline'   => 'বাংলা নমুনা ১২৩',
				'characters' => "অআইঈউঊএঐওঔকখগঘচছজঝ\nটঠডঢণতথদধনপফবভমযরলশষসহ\n১২৩৪৫৬৭৮৯০",
				'badge'      => 'অআ',
			),
			'adlam' => array(
				'headline'   => '𞤀𞤣𞤤𞤢𞤥 𞥑𞥒𞥓',
				'characters' => "𞤀𞤁𞤂𞤃𞤄𞤅𞤆𞤇𞤈𞤉𞤊𞤋𞤌𞤍𞤎𞤏𞤐\n𞤢𞤣𞤤𞤥𞤦𞤧𞤨𞤩𞤪𞤫𞤬𞤭𞤮𞤯𞤰𞤱𞤲\n𞥐𞥑𞥒𞥓𞥔𞥕𞥖𞥗𞥘𞥙",
				'badge'      => '𞤀𞤢',
			),
		);

		return isset( $samples[ $subset ] ) ? $samples[ $subset ] : $samples['latin'];
	}

	/**
	 * Draw text with TTF if available, otherwise use a builtin GD font.
	 *
	 * @param resource             $image Image resource.
	 * @param array<string,mixed>  $args  Draw arguments.
	 * @return void
	 */
	private function draw_text( $image, array $args ) {
		$text      = (string) $args['text'];
		$x         = (int) $args['x'];
		$y         = (int) $args['y'];
		$size      = (int) $args['size'];
		$color     = $args['color'];
		$font_path = (string) $args['font_path'];
		$builtin   = isset( $args['builtin'] ) ? (int) $args['builtin'] : 5;

		if ( $font_path && function_exists( 'imagettftext' ) && file_exists( $font_path ) ) {
			imagettftext( $image, $size, 0, $x, $y, $color, $font_path, $text );
			return;
		}

		imagestring( $image, max( 1, min( 5, $builtin ) ), $x, max( 0, $y - 18 ), $text, $color );
	}

	/**
	 * Get a renderable font file path.
	 *
	 * @param array<int, array<string, string>> $files Downloaded files.
	 * @return string
	 */
	private function get_renderable_font_path( array $files ) {
		foreach ( $files as $file ) {
			if ( empty( $file['extension'] ) || empty( $file['path'] ) ) {
				continue;
			}

			if ( in_array( strtolower( $file['extension'] ), array( 'ttf', 'otf' ), true ) ) {
				return $file['path'];
			}
		}

		return '';
	}

	/**
	 * Attach a generated local image file to a post.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $image_path Local image path.
	 * @param string $image_url  Public image URL.
	 * @param string $font_name  Font name.
	 * @return int|WP_Error
	 */
	private function attach_image_to_post( $post_id, $image_path, $image_url, $font_name ) {
		if ( ! file_exists( $image_path ) ) {
			return new WP_Error( 'kfi_featured_missing_file', 'Generated featured image file is missing.' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$filetype = wp_check_filetype( basename( $image_path ), null );

		$attachment = array(
			'guid'           => esc_url_raw( $image_url ),
			'post_mime_type' => $filetype['type'],
			'post_title'     => sprintf( '%s Featured Image', $font_name ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $image_path, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $image_path );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return (int) $attachment_id;
	}

	/**
	 * Ensure preview folder has a placeholder index file.
	 *
	 * @param string $directory Directory path.
	 * @return void
	 */
	private function ensure_preview_index( $directory ) {
		$index_file = trailingslashit( $directory ) . 'index.php';

		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}
	}
}

if ( ! function_exists( 'imagefilledroundedrectangle' ) ) {
	/**
	 * Draw a rounded rectangle using GD primitives.
	 *
	 * @param resource $image  Image resource.
	 * @param int      $x1     Left.
	 * @param int      $y1     Top.
	 * @param int      $x2     Right.
	 * @param int      $y2     Bottom.
	 * @param int      $radius Radius.
	 * @param int      $color  Allocated color.
	 * @return void
	 */
	function imagefilledroundedrectangle( $image, $x1, $y1, $x2, $y2, $radius, $color ) {
		imagefilledrectangle( $image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color );
		imagefilledrectangle( $image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color );
		imagefilledellipse( $image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color );
		imagefilledellipse( $image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color );
		imagefilledellipse( $image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color );
		imagefilledellipse( $image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color );
	}
}
