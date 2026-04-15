<?php
/**
 * Preview asset manager.
 *
 * @package KreativFontIngestor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KFI_Preview_Assets {
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
	 * Build or refresh a managed preview asset for a font family.
	 *
	 * @param array<string, mixed> $download Download payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public function ensure_preview_asset( array $download ) {
		$font_name = isset( $download['font_name'] ) ? sanitize_text_field( $download['font_name'] ) : '';
		$font_slug = isset( $download['font_slug'] ) ? sanitize_title( $download['font_slug'] ) : sanitize_title( $font_name );
		$files     = isset( $download['files'] ) && is_array( $download['files'] ) ? $download['files'] : array();

		if ( '' === $font_name || empty( $files ) ) {
			return new WP_Error( 'kfi_preview_invalid_download', 'Preview asset generation requires a valid downloaded font family.' );
		}

		$source = $this->select_source_file( $files );

		if ( empty( $source['path'] ) || empty( $source['extension'] ) ) {
			return new WP_Error( 'kfi_preview_missing_source', 'No suitable source file was found for preview asset generation.' );
		}

		$folder_path = trailingslashit( $download['folder_path'] ) . 'preview/';
		$folder_url  = trailingslashit( $download['folder_url'] ) . 'preview/';
		$extension   = strtolower( sanitize_text_field( $source['extension'] ) );
		$filename    = 'kfi-preview.' . $extension;
		$target_path = $folder_path . $filename;
		$target_url  = $folder_url . $filename;

		wp_mkdir_p( $folder_path );
		$this->ensure_preview_protection( $folder_path );

		if ( ! file_exists( $target_path ) || md5_file( $target_path ) !== md5_file( $source['path'] ) ) {
			if ( ! copy( $source['path'], $target_path ) ) {
				return new WP_Error( 'kfi_preview_copy_failed', 'Failed to copy the preview asset into the managed preview directory.' );
			}
		}

		$format = $this->normalize_font_format( $extension );
		$status = in_array( $extension, array( 'woff2', 'woff' ), true ) ? 'ready' : 'fallback_source';
		$note   = 'ready' === $status ? 'Managed webfont preview asset is ready.' : 'Managed preview asset uses an original source font until webfont conversion is added.';

		$manifest = array(
			'font_family'      => $font_name,
			'font_slug'        => $font_slug,
			'status'           => $status,
			'format'           => $format,
			'extension'        => $extension,
			'preview_url'      => $target_url,
			'preview_path'     => $target_path,
			'source_file'      => $source['filename'],
			'source_variant'   => $source['variant'],
			'source_path'      => $source['path'],
			'note'             => $note,
			'generated_at'     => gmdate( 'c' ),
		);

		file_put_contents(
			$folder_path . 'preview-manifest.json',
			wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			LOCK_EX
		);

		return $manifest;
	}

	/**
	 * Select the best source file for preview usage.
	 *
	 * @param array<int, array<string, string>> $files Downloaded files.
	 * @return array<string, string>
	 */
	private function select_source_file( array $files ) {
		$priorities = array(
			array( 'variant' => 'regular', 'extensions' => array( 'woff2', 'woff', 'ttf', 'otf' ) ),
			array( 'variant' => '400', 'extensions' => array( 'woff2', 'woff', 'ttf', 'otf' ) ),
			array( 'variant' => '', 'extensions' => array( 'woff2', 'woff' ) ),
			array( 'variant' => '', 'extensions' => array( 'ttf', 'otf' ) ),
		);

		foreach ( $priorities as $priority ) {
			foreach ( $files as $file ) {
				$variant   = isset( $file['variant'] ) ? sanitize_key( $file['variant'] ) : '';
				$extension = isset( $file['extension'] ) ? strtolower( sanitize_text_field( $file['extension'] ) ) : '';

				if ( '' !== $priority['variant'] && $variant !== $priority['variant'] ) {
					continue;
				}

				if ( in_array( $extension, $priority['extensions'], true ) ) {
					return $file;
				}
			}
		}

		return isset( $files[0] ) && is_array( $files[0] ) ? $files[0] : array();
	}

	/**
	 * Normalize file extension to CSS format value.
	 *
	 * @param string $extension File extension.
	 * @return string
	 */
	private function normalize_font_format( $extension ) {
		$map = array(
			'woff2' => 'woff2',
			'woff'  => 'woff',
			'ttf'   => 'truetype',
			'otf'   => 'opentype',
		);

		return isset( $map[ $extension ] ) ? $map[ $extension ] : '';
	}

	/**
	 * Add minimal protection files to preview directories.
	 *
	 * @param string $directory Preview directory.
	 * @return void
	 */
	private function ensure_preview_protection( $directory ) {
		$index_file = trailingslashit( $directory ) . 'index.php';

		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}
	}
}
