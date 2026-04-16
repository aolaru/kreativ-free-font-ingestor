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

		wp_mkdir_p( $folder_path );
		$this->ensure_preview_protection( $folder_path );
		$conversion = $this->build_preview_asset( $source, $folder_path, $folder_url );

		if ( is_wp_error( $conversion ) ) {
			return $conversion;
		}

		$manifest = array(
			'font_family'      => $font_name,
			'font_slug'        => $font_slug,
			'status'           => $conversion['status'],
			'format'           => $conversion['format'],
			'extension'        => $conversion['extension'],
			'preview_url'      => $conversion['preview_url'],
			'preview_path'     => $conversion['preview_path'],
			'source_file'      => $source['filename'],
			'source_variant'   => $source['variant'],
			'source_path'      => $source['path'],
			'note'             => $conversion['note'],
			'tool'             => $conversion['tool'],
			'toolchain_status' => $this->get_toolchain_status(),
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
	 * Generate a public webfont kit ZIP from managed preview assets.
	 *
	 * @param array<string, mixed> $download      Download payload.
	 * @param array<string, mixed> $preview_asset Managed preview asset.
	 * @return array<string, mixed>|WP_Error
	 */
	public function ensure_webfont_kit( array $download, array $preview_asset ) {
		if ( empty( $preview_asset['preview_path'] ) || empty( $preview_asset['preview_url'] ) ) {
			return new WP_Error( 'kfi_webfont_preview_missing', 'Managed preview asset is required before a webfont kit can be created.' );
		}

		$preview_format = ! empty( $preview_asset['format'] ) ? sanitize_text_field( $preview_asset['format'] ) : '';

		if ( ! in_array( $preview_format, array( 'woff2', 'woff' ), true ) ) {
			return new WP_Error( 'kfi_webfont_format_invalid', 'A public webfont kit can only be created from managed woff2 or woff preview assets.' );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'kfi_webfont_zip_missing', 'ZipArchive is required to create webfont kits.' );
		}

		$paths        = $this->logger->get_upload_paths();
		$font_name    = sanitize_text_field( $download['font_name'] );
		$font_slug    = sanitize_title( $download['font_slug'] );
		$zip_name     = sanitize_file_name( $font_slug . '-webfont.zip' );
		$zip_path     = $paths['packages'] . $zip_name;
		$zip_url      = $paths['packages_url'] . $zip_name;
		$preview_path = sanitize_text_field( $preview_asset['preview_path'] );
		$preview_file = basename( $preview_path );
		$license_path = isset( $download['license_path'] ) ? sanitize_text_field( $download['license_path'] ) : '';
		$css_content  = $this->build_webfont_stylesheet( $font_name, $preview_file, $preview_format );
		$zip          = new ZipArchive();
		$open         = $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		if ( true !== $open ) {
			return new WP_Error( 'kfi_webfont_zip_error', 'Failed to create the webfont kit ZIP archive.' );
		}

		$zip->addFile( $preview_path, $preview_file );
		$zip->addFromString( 'stylesheet.css', $css_content );

		if ( $license_path && file_exists( $license_path ) ) {
			$zip->addFile( $license_path, 'OFL.txt' );
		}

		$manifest = array(
			'font_family'    => $font_name,
			'font_slug'      => $font_slug,
			'preview_asset'  => $preview_file,
			'preview_format' => $preview_format,
			'preview_status' => ! empty( $preview_asset['status'] ) ? sanitize_text_field( $preview_asset['status'] ) : '',
			'stylesheet'     => 'stylesheet.css',
			'generated_at'   => gmdate( 'c' ),
			'note'           => 'Generated webfont kit for browser preview usage with a starter @font-face stylesheet. Original archive remains the canonical source package.',
		);

		$zip->addFromString( 'webfont-manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		if ( ! $zip->close() ) {
			return new WP_Error( 'kfi_webfont_zip_error', 'Failed to finalize the webfont kit ZIP archive.' );
		}

		return array(
			'zip_name' => $zip_name,
			'zip_path' => $zip_path,
			'zip_url'  => $zip_url,
			'zip_size' => file_exists( $zip_path ) ? (int) filesize( $zip_path ) : 0,
		);
	}

	/**
	 * Build starter stylesheet for the webfont kit.
	 *
	 * @param string $font_name    Font family.
	 * @param string $preview_file Preview filename.
	 * @param string $format       Preview format.
	 * @return string
	 */
	private function build_webfont_stylesheet( $font_name, $preview_file, $format ) {
		$family = addslashes( $font_name );
		$file   = rawurlencode( $preview_file );
		$format = sanitize_text_field( $format );

		return implode(
			"\n",
			array(
				'@font-face {',
				sprintf( "  font-family: '%s';", $family ),
				sprintf( "  src: url('./%s') format('%s');", $file, $format ),
				'  font-style: normal;',
				'  font-weight: 400;',
				'  font-display: swap;',
				'}',
				'',
				sprintf( ".font-preview-%s {", sanitize_title( $font_name ) ),
				sprintf( "  font-family: '%s', sans-serif;", $family ),
				'}',
				'',
				'body {',
				sprintf( "  font-family: '%s', sans-serif;", $family ),
				'}',
				'',
			)
		);
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
	 * Build managed preview asset with conversion when possible.
	 *
	 * @param array<string, string> $source      Source file.
	 * @param string                $folder_path Preview directory.
	 * @param string                $folder_url  Preview URL.
	 * @return array<string, string>|WP_Error
	 */
	private function build_preview_asset( array $source, $folder_path, $folder_url ) {
		$source_path = sanitize_text_field( $source['path'] );
		$extension   = strtolower( sanitize_text_field( $source['extension'] ) );

		if ( in_array( $extension, array( 'woff2', 'woff' ), true ) ) {
			$target_path = $folder_path . 'kfi-preview.' . $extension;
			$target_url  = $folder_url . 'kfi-preview.' . $extension;

			if ( ! file_exists( $target_path ) || md5_file( $target_path ) !== md5_file( $source_path ) ) {
				if ( ! copy( $source_path, $target_path ) ) {
					return new WP_Error( 'kfi_preview_copy_failed', 'Failed to copy the webfont preview asset.' );
				}
			}

			return array(
				'status'       => 'ready',
				'format'       => $this->normalize_font_format( $extension ),
				'extension'    => $extension,
				'preview_url'  => $target_url,
				'preview_path' => $target_path,
				'note'         => 'Managed webfont preview asset is ready.',
				'tool'         => 'direct-copy',
			);
		}

		if ( in_array( $extension, array( 'ttf', 'otf' ), true ) ) {
			$converted = $this->convert_to_woff2( $source_path, $folder_path, 'kfi-preview.woff2' );

			if ( ! is_wp_error( $converted ) ) {
				return array(
					'status'       => 'converted',
					'format'       => 'woff2',
					'extension'    => 'woff2',
					'preview_url'  => $folder_url . 'kfi-preview.woff2',
					'preview_path' => $converted['path'],
					'note'         => sprintf( 'Managed preview asset converted to woff2 using %s.', $converted['tool'] ),
					'tool'         => $converted['tool'],
				);
			}

			$target_path = $folder_path . 'kfi-preview.' . $extension;
			$target_url  = $folder_url . 'kfi-preview.' . $extension;

			if ( ! file_exists( $target_path ) || md5_file( $target_path ) !== md5_file( $source_path ) ) {
				if ( ! copy( $source_path, $target_path ) ) {
					return new WP_Error( 'kfi_preview_copy_failed', 'Failed to copy the fallback preview asset.' );
				}
			}

			return array(
				'status'       => 'fallback_source',
				'format'       => $this->normalize_font_format( $extension ),
				'extension'    => $extension,
				'preview_url'  => $target_url,
				'preview_path' => $target_path,
				'note'         => 'No local webfont converter was available, so the managed preview asset uses the original source file.',
				'tool'         => 'fallback-copy',
			);
		}

		return new WP_Error( 'kfi_preview_unsupported_source', 'Unsupported source file type for preview asset generation.' );
	}

	/**
	 * Convert a font source to woff2 if a supported tool is available.
	 *
	 * @param string $source_path Source path.
	 * @param string $folder_path Output directory.
	 * @param string $filename    Output filename.
	 * @return array<string, string>|WP_Error
	 */
	private function convert_to_woff2( $source_path, $folder_path, $filename ) {
		if ( $this->command_exists( 'pyftsubset' ) ) {
			$output_path = $folder_path . $filename;
			$command     = sprintf(
				'pyftsubset %s --output-file=%s --flavor=woff2 --unicodes=* --layout-features="*" --passthrough-tables 2>&1',
				escapeshellarg( $source_path ),
				escapeshellarg( $output_path )
			);
			$result      = $this->run_command( $command );

			if ( 0 === $result['code'] && file_exists( $output_path ) ) {
				return array(
					'path' => $output_path,
					'tool' => 'pyftsubset',
				);
			}
		}

		if ( $this->command_exists( 'woff2_compress' ) ) {
			$temp_source = $folder_path . basename( $source_path );

			if ( ! file_exists( $temp_source ) && ! copy( $source_path, $temp_source ) ) {
				return new WP_Error( 'kfi_preview_temp_copy_failed', 'Failed to prepare a temporary source file for woff2 conversion.' );
			}

			$command = sprintf(
				'woff2_compress %s 2>&1',
				escapeshellarg( $temp_source )
			);
			$result  = $this->run_command( $command );
			$output  = preg_replace( '/\.[^.]+$/', '.woff2', $temp_source );

			if ( 0 === $result['code'] && $output && file_exists( $output ) ) {
				$final_output = $folder_path . $filename;

				if ( ! rename( $output, $final_output ) ) {
					return new WP_Error( 'kfi_preview_output_move_failed', 'Failed to move the converted woff2 preview asset into place.' );
				}

				return array(
					'path' => $final_output,
					'tool' => 'woff2_compress',
				);
			}
		}

		return new WP_Error( 'kfi_preview_conversion_unavailable', 'No supported local webfont conversion tool is available.' );
	}

	/**
	 * Get current conversion toolchain status.
	 *
	 * @return array<string, bool>
	 */
	private function get_toolchain_status() {
		return array(
			'pyftsubset'     => $this->command_exists( 'pyftsubset' ),
			'woff2_compress' => $this->command_exists( 'woff2_compress' ),
		);
	}

	/**
	 * Check if a shell command exists.
	 *
	 * @param string $command Command name.
	 * @return bool
	 */
	private function command_exists( $command ) {
		if ( ! function_exists( 'shell_exec' ) ) {
			return false;
		}

		$output = shell_exec( sprintf( 'command -v %s 2>/dev/null', escapeshellarg( $command ) ) );

		return is_string( $output ) && '' !== trim( $output );
	}

	/**
	 * Run a shell command with captured output.
	 *
	 * @param string $command Shell command.
	 * @return array<string, mixed>
	 */
	private function run_command( $command ) {
		if ( ! function_exists( 'exec' ) ) {
			return array(
				'code'   => 1,
				'output' => 'exec() is disabled on this server.',
			);
		}

		$output = array();
		$code   = 1;

		exec( $command, $output, $code );

		return array(
			'code'   => (int) $code,
			'output' => implode( "\n", $output ),
		);
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
