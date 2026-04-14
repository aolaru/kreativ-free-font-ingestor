<?php
/**
 * Font downloader.
 *
 * @package KreativFontIngestor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KFI_Downloader {
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
	 */
	public function __construct( KFI_Logger $logger ) {
		$this->logger = $logger;
		$this->api    = new KFI_API( $logger );
	}

	/**
	 * Download all assets for a font family.
	 *
	 * @param array<string, mixed> $font Font data.
	 * @return array<string, mixed>|WP_Error
	 */
	public function download_font_family( array $font ) {
		$paths      = $this->logger->get_upload_paths();
		$font_name  = isset( $font['family'] ) ? sanitize_text_field( $font['family'] ) : '';
		$font_slug  = sanitize_title( $font_name );
		$folder     = trailingslashit( $paths['base_dir'] ) . $font_slug . '/';
		$folder_url = trailingslashit( $paths['base_url'] ) . $font_slug . '/';
		$files      = array();

		if ( empty( $font_name ) ) {
			return new WP_Error( 'kfi_invalid_font', 'Font family is missing.' );
		}

		wp_mkdir_p( $folder );

		if ( empty( $font['files'] ) || ! is_array( $font['files'] ) ) {
			return new WP_Error( 'kfi_missing_files', 'Google Fonts API did not provide variant files for this family.' );
		}

		$license_data = $this->api->get_ofl_license_data( $font_name );

		if ( is_wp_error( $license_data ) ) {
			return $license_data;
		}

		foreach ( $font['files'] as $variant => $url ) {
			$result = $this->download_file( $font_name, $variant, $url, $folder, $folder_url );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$files[] = $result;
		}

		$license_path = $folder . 'OFL.txt';
		$license_write = $this->write_file( $license_path, $license_data['license_text'] );

		if ( is_wp_error( $license_write ) ) {
			return $license_write;
		}

		$metadata = array(
			'family'         => $font_name,
			'slug'           => $font_slug,
			'variants'       => array_keys( $font['files'] ),
			'category'       => isset( $font['category'] ) ? sanitize_text_field( $font['category'] ) : '',
			'last_modified'  => isset( $font['lastModified'] ) ? sanitize_text_field( $font['lastModified'] ) : '',
			'version'        => isset( $font['version'] ) ? sanitize_text_field( $font['version'] ) : '',
			'subsets'        => isset( $font['subsets'] ) && is_array( $font['subsets'] ) ? array_map( 'sanitize_text_field', $font['subsets'] ) : array(),
			'license'        => 'SIL Open Font License 1.1',
			'license_file'   => 'OFL.txt',
			'license_source' => $license_data['license_url'],
			'attribution'    => 'Source catalog: Google Fonts API',
			'source_family'  => $font_name,
			'designer'       => isset( $font['designer'] ) ? sanitize_text_field( $font['designer'] ) : '',
			'foundry'        => isset( $font['foundry'] ) ? sanitize_text_field( $font['foundry'] ) : 'Google Fonts',
			'description'    => isset( $font['description_plain'] ) ? wp_strip_all_tags( $font['description_plain'] ) : '',
			'article'        => isset( $font['article_plain'] ) ? wp_strip_all_tags( $font['article_plain'] ) : '',
			'google_fonts_url' => isset( $font['google_fonts_url'] ) ? esc_url_raw( $font['google_fonts_url'] ) : '',
			'repo_directory' => isset( $font['repo_directory'] ) ? sanitize_text_field( $font['repo_directory'] ) : '',
			'is_variable'    => ! empty( $font['is_variable'] ),
			'axes'           => isset( $font['axes'] ) && is_array( $font['axes'] ) ? $font['axes'] : array(),
			'source_files'   => $files,
			'downloaded_at'  => gmdate( 'c' ),
		);

		$metadata_path = $folder . 'metadata.json';
		$metadata_write = $this->write_file( $metadata_path, wp_json_encode( $metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		if ( is_wp_error( $metadata_write ) ) {
			return $metadata_write;
		}

		$this->logger->info( sprintf( 'Downloaded %d variants for %s.', count( $files ), $font_name ) );

		return array(
			'font_name'     => $font_name,
			'font_slug'     => $font_slug,
			'folder_path'   => $folder,
			'folder_url'    => $folder_url,
			'files'         => $files,
			'license_path'  => $license_path,
			'license_url'   => $folder_url . 'OFL.txt',
			'license_type'  => $license_data['license_type'],
			'license_source_url' => $license_data['license_url'],
			'metadata_path' => $metadata_path,
			'metadata_url'  => $folder_url . 'metadata.json',
		);
	}

	/**
	 * Download a single remote file to the font folder.
	 *
	 * @param string $font_name  Font name.
	 * @param string $variant    Variant.
	 * @param string $url        File URL.
	 * @param string $folder     Folder path.
	 * @param string $folder_url Folder URL.
	 * @return array<string, string>|WP_Error
	 */
	private function download_file( $font_name, $variant, $url, $folder, $folder_url ) {
		$url = $this->normalize_font_url( $url );

		if ( empty( $url ) ) {
			return new WP_Error( 'kfi_invalid_url', sprintf( 'Variant URL missing for %s (%s).', $font_name, $variant ) );
		}

		$path_info = wp_parse_url( $url, PHP_URL_PATH );
		$extension = pathinfo( (string) $path_info, PATHINFO_EXTENSION );
		$extension = $extension ? strtolower( $extension ) : 'woff2';
		$filename  = sanitize_file_name( sanitize_title( $font_name ) . '-' . sanitize_key( $variant ) . '.' . $extension );
		$file_path = $folder . $filename;

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code || empty( $body ) ) {
			return new WP_Error( 'kfi_download_failed', sprintf( 'Unable to download variant %1$s for %2$s.', $variant, $font_name ) );
		}

		$file_write = $this->write_file( $file_path, $body );

		if ( is_wp_error( $file_write ) ) {
			return $file_write;
		}

		return array(
			'variant'   => sanitize_key( $variant ),
			'url'       => $folder_url . $filename,
			'path'      => $file_path,
			'source'    => $url,
			'filename'  => $filename,
			'extension' => $extension,
		);
	}

	/**
	 * Normalize and validate Google-hosted font file URLs.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private function normalize_font_url( $url ) {
		$url = esc_url_raw( $url );

		if ( 0 === strpos( $url, 'http://' ) ) {
			$url = 'https://' . substr( $url, 7 );
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! in_array( $host, array( 'fonts.gstatic.com', 'fonts.googleapis.com' ), true ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Write file contents with error handling.
	 *
	 * @param string $path    File path.
	 * @param string $content Content.
	 * @return true|WP_Error
	 */
	private function write_file( $path, $content ) {
		$result = file_put_contents( $path, $content, LOCK_EX );

		if ( false === $result ) {
			return new WP_Error( 'kfi_write_failed', sprintf( 'Failed to write file: %s', $path ) );
		}

		return true;
	}
}
