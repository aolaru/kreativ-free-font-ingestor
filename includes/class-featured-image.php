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
	 * @return int|WP_Error Attachment ID or error.
	 */
	public function generate_for_post( $post_id, array $download, array $font ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return new WP_Error( 'kfi_featured_invalid_post', 'Invalid post ID for featured image generation.' );
		}

		$existing_thumbnail = get_post_thumbnail_id( $post_id );

		if ( $existing_thumbnail ) {
			return (int) $existing_thumbnail;
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
		$this->logger->info( sprintf( 'Generated featured image for post #%d.', $post_id ) );

		return $attachment_id;
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

		$width  = 1600;
		$height = 900;
		$image  = imagecreatetruecolor( $width, $height );

		if ( ! $image ) {
			return new WP_Error( 'kfi_featured_create_failed', 'Unable to create image canvas.' );
		}

		imageantialias( $image, true );

		$bg_start   = array( 245, 241, 255 );
		$bg_end     = array( 255, 255, 255 );
		$violet     = imagecolorallocate( $image, 111, 70, 255 );
		$deep       = imagecolorallocate( $image, 34, 27, 64 );
		$soft_dark  = imagecolorallocate( $image, 89, 83, 118 );
		$mint       = imagecolorallocate( $image, 171, 255, 208 );
		$white      = imagecolorallocate( $image, 255, 255, 255 );
		$panel_fill = imagecolorallocatealpha( $image, 255, 255, 255, 16 );

		for ( $y = 0; $y < $height; $y++ ) {
			$ratio = $y / max( 1, $height - 1 );
			$red   = (int) round( $bg_start[0] + ( $bg_end[0] - $bg_start[0] ) * $ratio );
			$green = (int) round( $bg_start[1] + ( $bg_end[1] - $bg_start[1] ) * $ratio );
			$blue  = (int) round( $bg_start[2] + ( $bg_end[2] - $bg_start[2] ) * $ratio );
			$line  = imagecolorallocate( $image, $red, $green, $blue );
			imageline( $image, 0, $y, $width, $y, $line );
		}

		imagefilledellipse( $image, 1250, 110, 560, 560, imagecolorallocatealpha( $image, 143, 109, 255, 92 ) );
		imagefilledellipse( $image, 180, 780, 420, 420, imagecolorallocatealpha( $image, 142, 232, 188, 104 ) );
		imagefilledrectangle( $image, 84, 92, 1516, 808, $panel_fill );

		imagesetthickness( $image, 3 );
		imagerectangle( $image, 84, 92, 1516, 808, imagecolorallocatealpha( $image, 220, 214, 246, 36 ) );
		imagesetthickness( $image, 1 );

		$this->draw_text(
			$image,
			array(
				'text'      => 'KREATIV FONT',
				'x'         => 120,
				'y'         => 160,
				'size'      => 24,
				'color'     => $violet,
				'font_path' => '',
				'builtin'   => 5,
			)
		);

		$this->draw_text(
			$image,
			array(
				'text'      => $data['font_name'],
				'x'         => 120,
				'y'         => 320,
				'size'      => 92,
				'color'     => $deep,
				'font_path' => $data['font_path'],
				'builtin'   => 5,
			)
		);

		$this->draw_text(
			$image,
			array(
				'text'      => 'Free Download',
				'x'         => 120,
				'y'         => 405,
				'size'      => 48,
				'color'     => $soft_dark,
				'font_path' => '',
				'builtin'   => 5,
			)
		);

		$this->draw_text(
			$image,
			array(
				'text'      => sprintf( '%s font package with local ZIP, OFL license, and commercial-use guidance.', $data['font_name'] ),
				'x'         => 120,
				'y'         => 500,
				'size'      => 28,
				'color'     => $soft_dark,
				'font_path' => '',
				'builtin'   => 4,
			)
		);

		$this->draw_text(
			$image,
			array(
				'text'      => sprintf( '%s specimen', ucfirst( $data['category'] ) ),
				'x'         => 120,
				'y'         => 620,
				'size'      => 30,
				'color'     => $violet,
				'font_path' => '',
				'builtin'   => 5,
			)
		);

		$this->draw_text(
			$image,
			array(
				'text'      => 'The quick brown fox jumps over the lazy dog',
				'x'         => 120,
				'y'         => 720,
				'size'      => 46,
				'color'     => $deep,
				'font_path' => $data['font_path'],
				'builtin'   => 5,
			)
		);

		imagefilledroundedrectangle( $image, 1130, 640, 1440, 726, 22, $violet );
		$this->draw_text(
			$image,
			array(
				'text'      => 'Commercial Use',
				'x'         => 1180,
				'y'         => 696,
				'size'      => 26,
				'color'     => $white,
				'font_path' => '',
				'builtin'   => 5,
			)
		);

		imagefilledellipse( $image, 1360, 250, 160, 160, $mint );
		imagefilledellipse( $image, 1360, 250, 110, 110, $white );
		$this->draw_text(
			$image,
			array(
				'text'      => 'Aa',
				'x'         => 1316,
				'y'         => 282,
				'size'      => 56,
				'color'     => $deep,
				'font_path' => $data['font_path'],
				'builtin'   => 5,
			)
		);

		$result = imagepng( $image, $target_path );
		imagedestroy( $image );

		if ( ! $result ) {
			return new WP_Error( 'kfi_featured_write_failed', 'Unable to write generated featured image.' );
		}

		return true;
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
