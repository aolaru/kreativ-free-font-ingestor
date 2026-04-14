<?php
/**
 * ZIP generation service.
 *
 * @package KreativFontIngestor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KFI_Zipper {
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
	 * Create ZIP archive for a font folder.
	 *
	 * @param string $folder_path Source folder path.
	 * @param string $font_slug   Font slug.
	 * @return array<string, string>|WP_Error
	 */
	public function create_zip( $folder_path, $font_slug ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'kfi_zip_missing', 'ZipArchive is required to generate font packages.' );
		}

		if ( ! is_dir( $folder_path ) ) {
			return new WP_Error( 'kfi_zip_source_missing', 'Source font folder does not exist.' );
		}

		$paths    = $this->logger->get_upload_paths();
		$zip_name = sanitize_file_name( $font_slug . '-kreativ.zip' );
		$zip_path = $paths['packages'] . $zip_name;
		$zip_url  = $paths['packages_url'] . $zip_name;

		$zip = new ZipArchive();
		$open = $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		if ( true !== $open ) {
			return new WP_Error( 'kfi_zip_error', 'Failed to create ZIP archive.' );
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $folder_path, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$absolute_path = $file->getPathname();
			$relative_path = ltrim( str_replace( $folder_path, '', $absolute_path ), '/' );
			$zip->addFile( $absolute_path, $relative_path );
		}

		if ( ! $zip->close() ) {
			return new WP_Error( 'kfi_zip_error', 'Failed to finalize ZIP archive.' );
		}
		$this->logger->info( sprintf( 'Generated ZIP package for %s.', $font_slug ) );

		return array(
			'zip_name' => $zip_name,
			'zip_path' => $zip_path,
			'zip_url'  => $zip_url,
			'zip_size' => file_exists( $zip_path ) ? (int) filesize( $zip_path ) : 0,
		);
	}
}
