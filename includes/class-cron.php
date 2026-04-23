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
	 * Option name for cron runtime state.
	 */
	const STATUS_OPTION = 'kfi_cron_status';

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
		$event    = wp_get_scheduled_event( KFI_CRON_HOOK );

		if ( ! $enabled ) {
			wp_clear_scheduled_hook( KFI_CRON_HOOK );
			return;
		}

		if ( $event && isset( $event->schedule ) && 'kfi_every_eight_hours' !== $event->schedule ) {
			wp_clear_scheduled_hook( KFI_CRON_HOOK );
			$event = false;
		}

		if ( ! $event ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'kfi_every_eight_hours', KFI_CRON_HOOK );
		}
	}

	/**
	 * Add schedule interval.
	 *
	 * @param array<string, array<string, mixed>> $schedules Schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_schedule( $schedules ) {
		$schedules['kfi_every_eight_hours'] = array(
			'interval' => 8 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 8 Hours (KFI)', 'kreativ-font-ingestor' ),
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

		$limit = isset( $settings['import_limit'] ) ? absint( $settings['import_limit'] ) : 3;
		$started_at = current_time( 'mysql' );

		update_option(
			self::STATUS_OPTION,
			array(
				'last_started_at'  => $started_at,
				'last_finished_at' => '',
				'last_status'      => 'running',
				'imported'         => 0,
				'skipped'          => 0,
				'error_count'      => 0,
			),
			false
		);

		$results = $this->plugin->run_import( $limit, false );

		update_option(
			self::STATUS_OPTION,
			array(
				'last_started_at'  => $started_at,
				'last_finished_at' => current_time( 'mysql' ),
				'last_status'      => empty( $results['errors'] ) ? 'success' : 'completed_with_errors',
				'imported'         => isset( $results['imported'] ) ? absint( $results['imported'] ) : 0,
				'skipped'          => isset( $results['skipped'] ) ? absint( $results['skipped'] ) : 0,
				'error_count'      => isset( $results['errors'] ) && is_array( $results['errors'] ) ? count( $results['errors'] ) : 0,
			),
			false
		);
	}

	/**
	 * Get cron status details for admin display.
	 *
	 * @return array<string, mixed>
	 */
	public function get_status_summary() {
		$settings = $this->plugin->get_settings();
		$state    = get_option( self::STATUS_OPTION, array() );
		$next_run = wp_next_scheduled( KFI_CRON_HOOK );

		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'enabled'          => ! empty( $settings['cron_enabled'] ),
				'next_run'         => $next_run ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_run ), 'Y-m-d H:i:s' ) : '',
				'last_started_at'  => '',
				'last_finished_at' => '',
				'last_status'      => 'idle',
				'imported'         => 0,
				'skipped'          => 0,
				'error_count'      => 0,
			)
		);
	}
}
