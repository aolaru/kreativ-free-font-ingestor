<?php
/**
 * Google Fonts API client.
 *
 * @package KreativFontIngestor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KFI_API {
	/**
	 * Logger instance.
	 *
	 * @var KFI_Logger
	 */
	private $logger;

	/**
	 * Base URL for the Google Fonts GitHub repository raw files.
	 *
	 * @var string
	 */
	private $license_repo_base = 'https://raw.githubusercontent.com/google/fonts/main/';

	/**
	 * Standard OFL text bundled with the plugin for compliant redistribution.
	 *
	 * @var string
	 */
	private $ofl_text = "SIL OPEN FONT LICENSE Version 1.1 - 26 February 2007\n\nPREAMBLE\nThe goals of the Open Font License (OFL) are to stimulate worldwide development of collaborative font projects, to support the font creation efforts of academic and linguistic communities, and to provide a free and open framework in which fonts may be shared and improved in partnership with others.\n\nThe OFL allows the licensed fonts to be used, studied, modified and redistributed freely as long as they are not sold by themselves. The fonts, including any derivative works, can be bundled, embedded, redistributed and/or sold with any software provided that any reserved names are not used by derivative works. The fonts and derivatives, however, cannot be released under any other type of license. The requirement for fonts to remain under this license does not apply to any document created using the fonts or their derivatives.\n\nDEFINITIONS\n\"Font Software\" refers to the set of files released by the Copyright Holder(s) under this license and clearly marked as such. This may include source files, build scripts and documentation.\n\n\"Reserved Font Name\" refers to any names specified as such after the copyright statement(s).\n\n\"Original Version\" refers to the collection of Font Software components as distributed by the Copyright Holder(s).\n\n\"Modified Version\" refers to any derivative made by adding to, deleting, or substituting in part or in whole any of the components of the Original Version, by changing formats or by porting the Font Software to a new environment.\n\n\"Author\" refers to any designer, engineer, programmer, technical writer or other person who contributed to the Font Software.\n\nPERMISSION & CONDITIONS\nPermission is hereby granted, free of charge, to any person obtaining a copy of the Font Software, to use, study, copy, merge, embed, modify, redistribute, and sell modified and unmodified copies of the Font Software, subject to the following conditions:\n\n1) Neither the Font Software nor any of its individual components, in Original or Modified Versions, may be sold by itself.\n\n2) Original or Modified Versions of the Font Software may be bundled, redistributed and/or sold with any software, provided that each copy contains the above copyright notice and this license. These can be included either as stand-alone text files, human-readable headers or in the appropriate machine-readable metadata fields within text or binary files as long as those fields can be easily viewed by the user.\n\n3) No Modified Version of the Font Software may use the Reserved Font Name(s) unless explicit written permission is granted by the corresponding Copyright Holder. This restriction only applies to the primary font name as presented to the users.\n\n4) The name(s) of the Copyright Holder(s) or the Author(s) of the Font Software shall not be used to promote, endorse or advertise any Modified Version, except to acknowledge the contribution(s) of the Copyright Holder(s) and the Author(s) or with their explicit written permission.\n\n5) The Font Software, modified or unmodified, in part or in whole, must be distributed entirely under this license, and must not be distributed under any other license. The requirement for fonts to remain under this license does not apply to any document created using the Font Software.\n\nTERMINATION\nThis license becomes null and void if any of the above conditions are not met.\n\nDISCLAIMER\nTHE FONT SOFTWARE IS PROVIDED \"AS IS\", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO ANY WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT OF COPYRIGHT, PATENT, TRADEMARK, OR OTHER RIGHT. IN NO EVENT SHALL THE COPYRIGHT HOLDER BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, INCLUDING ANY GENERAL, SPECIAL, INDIRECT, INCIDENTAL, OR CONSEQUENTIAL DAMAGES, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF THE USE OR INABILITY TO USE THE FONT SOFTWARE OR FROM OTHER DEALINGS IN THE FONT SOFTWARE.";

	/**
	 * Constructor.
	 *
	 * @param KFI_Logger $logger Logger.
	 */
	public function __construct( KFI_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Get Google Fonts catalog.
	 *
	 * @param string $api_key API key.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function get_fonts( $api_key ) {
		$cached = get_transient( KFI_TRANSIENT_FONTS );

		if ( false !== $cached && is_array( $cached ) ) {
			$this->logger->info( 'Loaded Google Fonts catalog from transient cache.' );
			return $cached;
		}

		$url      = add_query_arg(
			array(
				'key'  => $api_key,
				'sort' => 'alpha',
			),
			'https://www.googleapis.com/webfonts/v1/webfonts'
		);
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return new WP_Error( 'kfi_api_error', sprintf( 'Google Fonts API returned HTTP %d.', $code ) );
		}

		$data = json_decode( $body, true );

		if ( empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return new WP_Error( 'kfi_api_empty', 'Google Fonts API returned an empty catalog.' );
		}

		set_transient( KFI_TRANSIENT_FONTS, $data['items'], 12 * HOUR_IN_SECONDS );
		$this->logger->info( sprintf( 'Fetched %d fonts from Google Fonts API.', count( $data['items'] ) ) );

		return $data['items'];
	}

	/**
	 * Get OFL text.
	 *
	 * @return string
	 */
	public function get_ofl_text() {
		return $this->ofl_text;
	}

	/**
	 * Resolve OFL license text for a Google Fonts family using the official repository structure.
	 *
	 * @param string $family Font family name.
	 * @return array<string, string>|WP_Error
	 */
	public function get_ofl_license_data( $family ) {
		$family = sanitize_text_field( $family );

		if ( '' === $family ) {
			return new WP_Error( 'kfi_license_missing_family', 'Font family is missing for license verification.' );
		}

		$candidates          = $this->get_family_directory_candidates( $family );
		$attempted_ofl_paths = array();
		$request_reason      = '';

		foreach ( $candidates as $directory ) {
			$ofl_path = 'ofl/' . $directory . '/OFL.txt';
			$ofl_url  = $this->license_repo_base . $ofl_path;
			$attempted_ofl_paths[] = $ofl_path;
			$ofl_text = $this->fetch_text_file( $ofl_url );

			if ( ! is_wp_error( $ofl_text ) ) {
				return array(
					'license_type' => 'OFL-1.1',
					'license_text' => $ofl_text,
					'license_url'  => $ofl_url,
					'repo_path'    => $ofl_path,
					'verification_status' => 'verified',
				);
			}

			$ofl_error_data = $ofl_text->get_error_data();

			if ( is_array( $ofl_error_data ) && ! empty( $ofl_error_data['reason'] ) ) {
				$request_reason = sanitize_text_field( $ofl_error_data['reason'] );
			}

			foreach ( array( 'apache/' . $directory . '/LICENSE.txt', 'ufl/' . $directory . '/UFL.txt' ) as $non_ofl_path ) {
				$non_ofl_url  = $this->license_repo_base . $non_ofl_path;
				$non_ofl_text = $this->fetch_text_file( $non_ofl_url );

				if ( ! is_wp_error( $non_ofl_text ) ) {
					return new WP_Error(
						'kfi_non_ofl_font',
						sprintf( 'Skipped %s because the official Google Fonts repository classifies it under a non-OFL license path.', $family ),
						array(
							'family'          => $family,
							'classification'  => 'non_ofl',
							'matched_path'    => $non_ofl_path,
							'attempted_paths' => $attempted_ofl_paths,
						)
					);
				}
			}
		}

		return new WP_Error(
			'kfi_unverified_license',
			sprintf( 'Skipped %s because its OFL license could not be verified from the official Google Fonts repository.', $family ),
			array(
				'family'          => $family,
				'classification'  => '' !== $request_reason ? 'request_error' : 'unverified',
				'attempted_paths' => $attempted_ofl_paths,
				'request_reason'  => $request_reason,
			)
		);
	}

	/**
	 * Fetch companion repository assets for a Google Fonts family.
	 *
	 * @param string $family Font family name.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_repository_bundle( $family ) {
		$family    = sanitize_text_field( $family );
		$cache_key = 'kfi_repo_bundle_' . md5( strtolower( $family ) );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$license_data = $this->get_ofl_license_data( $family );

		if ( is_wp_error( $license_data ) ) {
			return $license_data;
		}

		$repo_directory   = dirname( $license_data['repo_path'] );
		$metadata_url     = $this->license_repo_base . $repo_directory . '/METADATA.pb';
		$description_url  = $this->license_repo_base . $repo_directory . '/DESCRIPTION.en_us.html';
		$article_url      = $this->license_repo_base . $repo_directory . '/ARTICLE.en_us.html';
		$metadata_text    = $this->fetch_optional_text_file( $metadata_url );
		$description_html = $this->fetch_optional_text_file( $description_url );
		$article_html     = $this->fetch_optional_text_file( $article_url );
		$bundle           = array(
			'license_data'      => $license_data,
			'repo_directory'    => $repo_directory,
			'metadata_url'      => $metadata_url,
			'metadata_text'     => $metadata_text,
			'description_url'   => $description_url,
			'description_html'  => $description_html,
			'article_url'       => $article_url,
			'article_html'      => $article_html,
			'google_fonts_url'  => 'https://fonts.google.com/specimen/' . rawurlencode( $family ),
		);

		set_transient( $cache_key, $bundle, 12 * HOUR_IN_SECONDS );

		return $bundle;
	}

	/**
	 * Fetch plain text file from a trusted source.
	 *
	 * @param string $url Remote URL.
	 * @return string|WP_Error
	 */
	private function fetch_text_file( $url ) {
		$response = wp_safe_remote_get(
			esc_url_raw( $url ),
			array(
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'kfi_remote_request_failed',
				'Remote file request failed.',
				array(
					'url'    => esc_url_raw( $url ),
					'reason' => $response->get_error_message(),
				)
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			return new WP_Error(
				'kfi_remote_missing',
				'Remote file not found.',
				array(
					'url'         => esc_url_raw( $url ),
					'status_code' => $status_code,
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			return new WP_Error(
				'kfi_remote_empty',
				'Remote file was empty.',
				array(
					'url' => esc_url_raw( $url ),
				)
			);
		}

		return $body;
	}

	/**
	 * Fetch plain text file, but tolerate missing companion assets.
	 *
	 * @param string $url Remote URL.
	 * @return string
	 */
	private function fetch_optional_text_file( $url ) {
		$response = $this->fetch_text_file( $url );

		if ( is_wp_error( $response ) ) {
			return '';
		}

		return $response;
	}

	/**
	 * Build likely Google Fonts repository directory names.
	 *
	 * @param string $family Family name.
	 * @return array<int, string>
	 */
	private function get_family_directory_candidates( $family ) {
		$ascii   = strtolower( remove_accents( $family ) );
		$compact = preg_replace( '/[^a-z0-9]/', '', $ascii );
		$slug    = sanitize_title( $family );

		return array_values(
			array_unique(
				array_filter(
					array(
						$compact,
						str_replace( '-', '', $slug ),
						$slug,
					)
				)
			)
		);
	}
}
