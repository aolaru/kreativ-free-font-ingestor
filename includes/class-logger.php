<?php
/**
 * Logger service.
 *
 * @package KreativFontIngestor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KFI_Logger {
	/**
	 * Get plugin uploads directory data.
	 *
	 * @return array<string, string>
	 */
	public function get_upload_paths() {
		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] ) . 'kreativ-fonts/';
		$base_url   = trailingslashit( $upload_dir['baseurl'] ) . 'kreativ-fonts/';

		return array(
			'base_dir'   => $base_dir,
			'base_url'   => $base_url,
			'logs_file'  => $base_dir . 'logs.txt',
			'packages'   => $base_dir . 'packages/',
			'packages_url' => $base_url . 'packages/',
			'previews'   => $base_dir . 'previews/',
			'previews_url' => $base_url . 'previews/',
		);
	}

	/**
	 * Ensure required directories exist.
	 *
	 * @return void
	 */
	public function ensure_base_paths() {
		$paths = $this->get_upload_paths();

		wp_mkdir_p( $paths['base_dir'] );
		wp_mkdir_p( $paths['packages'] );
		wp_mkdir_p( $paths['previews'] );
		$this->ensure_protection_files( $paths['base_dir'] );
		$this->ensure_protection_files( $paths['packages'] );
		$this->ensure_protection_files( $paths['previews'] );

		if ( ! file_exists( $paths['logs_file'] ) ) {
			file_put_contents( $paths['logs_file'], '' );
		}
	}

	/**
	 * Write info log.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function info( $message ) {
		$this->write( 'INFO', $message );
	}

	/**
	 * Write error log.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function error( $message ) {
		$this->write( 'ERROR', $message );
	}

	/**
	 * Write log line.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @return void
	 */
	private function write( $level, $message ) {
		$this->ensure_base_paths();
		$paths = $this->get_upload_paths();
		$line  = sprintf(
			"[%s] [%s] %s\n",
			gmdate( 'Y-m-d H:i:s' ),
			sanitize_text_field( $level ),
			sanitize_textarea_field( $message )
		);

		file_put_contents( $paths['logs_file'], $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Read tail from logs.
	 *
	 * @param int $max_lines Max lines.
	 * @return string
	 */
	public function get_logs( $max_lines = 200 ) {
		$this->ensure_base_paths();
		$paths = $this->get_upload_paths();

		if ( ! file_exists( $paths['logs_file'] ) ) {
			return '';
		}

		$content = file( $paths['logs_file'], FILE_IGNORE_NEW_LINES );

		if ( false === $content ) {
			return '';
		}

		$content = array_slice( $content, -1 * absint( $max_lines ) );

		return implode( "\n", $content );
	}

	/**
	 * Create minimal directory protection files.
	 *
	 * @param string $directory Directory path.
	 * @return void
	 */
	private function ensure_protection_files( $directory ) {
		$index_file = trailingslashit( $directory ) . 'index.php';

		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}

		$htaccess_file = trailingslashit( $directory ) . '.htaccess';

		if ( ! file_exists( $htaccess_file ) ) {
			file_put_contents( $htaccess_file, "Options -Indexes\n<Files logs.txt>\nRequire all denied\n</Files>\n" );
		}
	}
}
