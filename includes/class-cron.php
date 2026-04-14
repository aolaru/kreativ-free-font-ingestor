<?php
/**
 * Cron controller.
 *
 * @package KreativFontIngestor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KFI_Cron {
	/**
	 * Plugin instance.
	 *
	 * @var KFI_Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param KFI_Plugin $plugin Plugin.
	 */
	public function __construct( KFI_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( KFI_CRON_HOOK, array( $this, 'run' ) );
		add_action( 'update_option_' . KFI_OPTION_SETTINGS, array( $this, 'sync_schedule' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'register_schedule' ) );
	}

	/**
	 * Register event if needed.
	 *
	 * @return void
	 */
	public static function register_schedule() {
		$settings = get_option( KFI_OPTION_SETTINGS, array() );
		$enabled  = isset( $settings['cron_enabled'] ) ? (int) $settings['cron_enabled'] : 1;

		if ( $enabled && ! wp_next_scheduled( KFI_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'kfi_every_six_hours', KFI_CRON_HOOK );
		}
	}

	/**
	 * Add schedule interval.
	 *
	 * @param array<string, array<string, mixed>> $schedules Schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_schedule( $schedules ) {
		$schedules['kfi_every_six_hours'] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 Hours (KFI)', 'kreativ-font-ingestor' ),
		);

		return $schedules;
	}

	/**
	 * Sync schedule when settings change.
	 *
	 * @param mixed $old_value Old value.
	 * @param mixed $new_value New value.
	 * @return void
	 */
	public function sync_schedule( $old_value, $new_value ) {
		$enabled = isset( $new_value['cron_enabled'] ) ? (int) $new_value['cron_enabled'] : 0;

		if ( $enabled ) {
			self::register_schedule();
			return;
		}

		wp_clear_scheduled_hook( KFI_CRON_HOOK );
	}

	/**
	 * Execute scheduled import.
	 *
	 * @return void
	 */
	public function run() {
		$settings = $this->plugin->get_settings();

		if ( empty( $settings['cron_enabled'] ) ) {
			return;
		}

		$limit = isset( $settings['import_limit'] ) ? absint( $settings['import_limit'] ) : 10;
		$this->plugin->run_import( $limit, false );
	}
}
