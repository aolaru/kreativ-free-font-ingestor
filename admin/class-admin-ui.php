<?php
/**
 * Admin UI controller.
 *
 * @package KreativFontIngestor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KFI_Admin_UI {
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

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_debug_meta_box' ) );
		add_action( 'admin_post_kfi_manual_import', array( $this, 'handle_manual_import' ) );
		add_action( 'admin_post_kfi_regenerate_posts', array( $this, 'handle_regeneration' ) );
		add_action( 'admin_post_kfi_backfill_preview_assets', array( $this, 'handle_preview_backfill' ) );
	}

	/**
	 * Register post-level debug meta box for imported font posts.
	 *
	 * @return void
	 */
	public function register_debug_meta_box() {
		add_meta_box(
			'kfi-preview-debug',
			__( 'KFI Preview Debug', 'kreativ-font-ingestor' ),
			array( $this, 'render_debug_meta_box' ),
			'post',
			'side',
			'high'
		);
	}

	/**
	 * Register admin menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Kreativ Free Fonts', 'kreativ-font-ingestor' ),
			__( 'Free Fonts', 'kreativ-font-ingestor' ),
			'manage_options',
			'kreativ-font-ingestor',
			array( $this, 'render_page' ),
			'dashicons-editor-textcolor',
			58
		);
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'kfi_settings_group',
			KFI_OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->plugin->get_settings(),
			)
		);

		add_settings_section(
			'kfi_main_section',
			__( 'Font Import Settings', 'kreativ-font-ingestor' ),
			'__return_false',
			'kfi_settings'
		);

		add_settings_field(
			'api_key',
			__( 'Google Fonts API Key', 'kreativ-font-ingestor' ),
			array( $this, 'render_api_key_field' ),
			'kfi_settings',
			'kfi_main_section'
		);

		add_settings_field(
			'cron_enabled',
			__( 'Cron Import', 'kreativ-font-ingestor' ),
			array( $this, 'render_cron_field' ),
			'kfi_settings',
			'kfi_main_section'
		);

		add_settings_field(
			'import_limit',
			__( 'Import Limit Per Run', 'kreativ-font-ingestor' ),
			array( $this, 'render_import_limit_field' ),
			'kfi_settings',
			'kfi_main_section'
		);

		add_settings_field(
			'category_id',
			__( 'Primary Archive Category', 'kreativ-font-ingestor' ),
			array( $this, 'render_category_field' ),
			'kfi_settings',
			'kfi_main_section'
		);

		add_settings_field(
			'taxonomy_parents',
			__( 'Category Parent Names', 'kreativ-font-ingestor' ),
			array( $this, 'render_taxonomy_parents_field' ),
			'kfi_settings',
			'kfi_main_section'
		);
	}

	/**
	 * Sanitize settings input.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( $input ) {
		return array(
			'api_key'                  => isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '',
			'cron_enabled'             => isset( $input['cron_enabled'] ) ? 1 : 0,
			'import_limit'             => isset( $input['import_limit'] ) ? max( 1, absint( $input['import_limit'] ) ) : 3,
			'category_id'              => isset( $input['category_id'] ) ? absint( $input['category_id'] ) : 0,
			'taxonomy_parent_fonts'    => isset( $input['taxonomy_parent_fonts'] ) ? sanitize_text_field( $input['taxonomy_parent_fonts'] ) : 'Fonts',
			'taxonomy_parent_designer' => isset( $input['taxonomy_parent_designer'] ) ? sanitize_text_field( $input['taxonomy_parent_designer'] ) : 'Designer',
			'taxonomy_parent_foundry'  => isset( $input['taxonomy_parent_foundry'] ) ? sanitize_text_field( $input['taxonomy_parent_foundry'] ) : 'Foundry',
			'taxonomy_parent_style'    => isset( $input['taxonomy_parent_style'] ) ? sanitize_text_field( $input['taxonomy_parent_style'] ) : 'Font Style',
			'taxonomy_parent_mood'     => isset( $input['taxonomy_parent_mood'] ) ? sanitize_text_field( $input['taxonomy_parent_mood'] ) : 'Font Mood',
			'taxonomy_parent_use_case' => isset( $input['taxonomy_parent_use_case'] ) ? sanitize_text_field( $input['taxonomy_parent_use_case'] ) : 'Font Use Case',
		);
	}

	/**
	 * Render API key field.
	 *
	 * @return void
	 */
	public function render_api_key_field() {
		$settings = $this->plugin->get_settings();
		?>
		<input type="text" name="<?php echo esc_attr( KFI_OPTION_SETTINGS ); ?>[api_key]" value="<?php echo esc_attr( $settings['api_key'] ); ?>" class="regular-text" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Create an API key with access to the Google Fonts Developer API.', 'kreativ-font-ingestor' ); ?></p>
		<?php
	}

	/**
	 * Render cron field.
	 *
	 * @return void
	 */
	public function render_cron_field() {
		$settings = $this->plugin->get_settings();
		?>
		<label for="kfi-cron-enabled">
			<input id="kfi-cron-enabled" type="checkbox" name="<?php echo esc_attr( KFI_OPTION_SETTINGS ); ?>[cron_enabled]" value="1" <?php checked( ! empty( $settings['cron_enabled'] ) ); ?> />
			<?php esc_html_e( 'Enable automatic imports every 8 hours.', 'kreativ-font-ingestor' ); ?>
		</label>
		<?php
	}

	/**
	 * Render limit field.
	 *
	 * @return void
	 */
	public function render_import_limit_field() {
		$settings = $this->plugin->get_settings();
		?>
		<input type="number" min="1" max="50" name="<?php echo esc_attr( KFI_OPTION_SETTINGS ); ?>[import_limit]" value="<?php echo esc_attr( $settings['import_limit'] ); ?>" />
		<p class="description"><?php esc_html_e( 'Batch size for manual and scheduled imports. Default: 3 fonts every 8 hours.', 'kreativ-font-ingestor' ); ?></p>
		<?php
	}

	/**
	 * Render primary archive category selector.
	 *
	 * @return void
	 */
	public function render_category_field() {
		$settings = $this->plugin->get_settings();
		wp_dropdown_categories(
			array(
				'taxonomy'         => 'category',
				'name'             => KFI_OPTION_SETTINGS . '[category_id]',
				'selected'         => absint( $settings['category_id'] ),
				'show_option_none' => __( 'Use default fallback (Free Fonts)', 'kreativ-font-ingestor' ),
				'hide_empty'       => false,
				'orderby'          => 'name',
			)
		);
		?>
		<p class="description"><?php esc_html_e( 'Choose the main category assigned to imported font posts.', 'kreativ-font-ingestor' ); ?></p>
		<?php
	}

	/**
	 * Render taxonomy parent category fields.
	 *
	 * @return void
	 */
	public function render_taxonomy_parents_field() {
		$settings = $this->plugin->get_settings();
		$fields   = array(
			'taxonomy_parent_fonts'    => __( 'Fonts', 'kreativ-font-ingestor' ),
			'taxonomy_parent_designer' => __( 'Designer', 'kreativ-font-ingestor' ),
			'taxonomy_parent_foundry'  => __( 'Foundry', 'kreativ-font-ingestor' ),
			'taxonomy_parent_style'    => __( 'Font Style', 'kreativ-font-ingestor' ),
			'taxonomy_parent_mood'     => __( 'Font Mood', 'kreativ-font-ingestor' ),
			'taxonomy_parent_use_case' => __( 'Font Use Case', 'kreativ-font-ingestor' ),
		);
		?>
		<div style="display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:12px;max-width:720px;">
			<?php foreach ( $fields as $key => $label ) : ?>
				<label>
					<span style="display:block;font-weight:600;margin-bottom:4px;"><?php echo esc_html( $label ); ?></span>
					<input type="text" class="regular-text" name="<?php echo esc_attr( KFI_OPTION_SETTINGS ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $settings[ $key ] ); ?>" />
				</label>
			<?php endforeach; ?>
		</div>
		<p class="description"><?php esc_html_e( 'These configurable parent categories are used when assigning imported fonts into your hierarchical catalog structure.', 'kreativ-font-ingestor' ); ?></p>
		<?php
	}

	/**
	 * Handle manual import action.
	 *
	 * @return void
	 */
	public function handle_manual_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'kreativ-font-ingestor' ) );
		}

		check_admin_referer( 'kfi_manual_import' );

		$settings = $this->plugin->get_settings();
		$limit    = isset( $settings['import_limit'] ) ? absint( $settings['import_limit'] ) : 3;
		$results  = $this->plugin->run_import( $limit, true );

		$query_args = array(
			'page'     => 'kreativ-font-ingestor',
			'imported' => $results['imported'],
			'skipped'  => $results['skipped'],
			'errors'   => count( $results['errors'] ),
		);

		wp_safe_redirect( add_query_arg( $query_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle regeneration actions.
	 *
	 * @return void
	 */
	public function handle_regeneration() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'kreativ-font-ingestor' ) );
		}

		check_admin_referer( 'kfi_regenerate_posts' );

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$limit   = isset( $_POST['limit'] ) ? max( 1, absint( wp_unslash( $_POST['limit'] ) ) ) : 10;
		$results = $this->plugin->regenerate_imported_posts( $post_id, $limit );

		$query_args = array(
			'page'           => 'kreativ-font-ingestor',
			'regenerated'    => $results['regenerated'],
			'regen_errors'   => count( $results['errors'] ),
		);

		wp_safe_redirect( add_query_arg( $query_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle preview backfill actions.
	 *
	 * @return void
	 */
	public function handle_preview_backfill() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'kreativ-font-ingestor' ) );
		}

		check_admin_referer( 'kfi_backfill_preview_assets' );

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$limit   = isset( $_POST['limit'] ) ? max( 1, absint( wp_unslash( $_POST['limit'] ) ) ) : 25;
		$results = $this->plugin->backfill_preview_assets( $post_id, $limit );

		$query_args = array(
			'page'                  => 'kreativ-font-ingestor',
			'preview_backfilled'    => $results['updated'],
			'preview_backfill_errors' => count( $results['errors'] ),
		);

		wp_safe_redirect( add_query_arg( $query_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$logger        = $this->plugin->get_logger();
		$logs          = $logger->get_logs();
		$cron_status   = $this->plugin->get_cron()->get_status_summary();
		$tracker       = $this->plugin->get_download_tracker();
		$download_totals = $tracker->get_overview_stats();
		$top_downloads   = $tracker->get_top_downloads( 10 );
		$recent_downloads = $tracker->get_recent_downloads( 15 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Kreativ Free Fonts', 'kreativ-font-ingestor' ); ?></h1>

			<?php if ( isset( $_GET['imported'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: imported, 2: skipped, 3: errors */
								__( 'Import completed. Imported: %1$d, Skipped: %2$d, Errors: %3$d.', 'kreativ-font-ingestor' ),
								absint( wp_unslash( $_GET['imported'] ) ),
								absint( wp_unslash( $_GET['skipped'] ) ),
								absint( wp_unslash( $_GET['errors'] ) )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['regenerated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: regenerated, 2: errors */
								__( 'Regeneration completed. Updated: %1$d, Errors: %2$d.', 'kreativ-font-ingestor' ),
								absint( wp_unslash( $_GET['regenerated'] ) ),
								absint( wp_unslash( $_GET['regen_errors'] ) )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['preview_backfilled'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: updated, 2: errors */
								__( 'Preview asset backfill completed. Updated: %1$d, Errors: %2$d.', 'kreativ-font-ingestor' ),
								absint( wp_unslash( $_GET['preview_backfilled'] ) ),
								absint( wp_unslash( $_GET['preview_backfill_errors'] ) )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'kfi_settings_group' );
				do_settings_sections( 'kfi_settings' );
				submit_button( __( 'Save Settings', 'kreativ-font-ingestor' ) );
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Cron Status', 'kreativ-font-ingestor' ); ?></h2>
			<div style="display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:12px;max-width:920px;margin-bottom:16px;">
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:14px;">
					<strong style="display:block;font-size:18px;"><?php echo esc_html( ! empty( $cron_status['enabled'] ) ? __( 'Enabled', 'kreativ-font-ingestor' ) : __( 'Disabled', 'kreativ-font-ingestor' ) ); ?></strong>
					<span><?php esc_html_e( 'Cron Status', 'kreativ-font-ingestor' ); ?></span>
				</div>
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:14px;">
					<strong style="display:block;font-size:18px;"><?php echo esc_html( ! empty( $cron_status['next_run'] ) ? $cron_status['next_run'] : '—' ); ?></strong>
					<span><?php esc_html_e( 'Next Scheduled Run', 'kreativ-font-ingestor' ); ?></span>
				</div>
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:14px;">
					<strong style="display:block;font-size:18px;"><?php echo esc_html( ! empty( $cron_status['last_finished_at'] ) ? $cron_status['last_finished_at'] : '—' ); ?></strong>
					<span><?php esc_html_e( 'Last Finished Run', 'kreativ-font-ingestor' ); ?></span>
				</div>
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:14px;">
					<strong style="display:block;font-size:18px;"><?php echo esc_html( ! empty( $cron_status['last_status'] ) ? sanitize_text_field( ucwords( str_replace( '_', ' ', $cron_status['last_status'] ) ) ) : '—' ); ?></strong>
					<span><?php esc_html_e( 'Last Run Status', 'kreativ-font-ingestor' ); ?></span>
				</div>
			</div>
			<table class="widefat striped" style="max-width:920px;margin-bottom:16px;">
				<tbody>
					<tr>
						<th style="width:220px;"><?php esc_html_e( 'Last Started At', 'kreativ-font-ingestor' ); ?></th>
						<td><?php echo esc_html( ! empty( $cron_status['last_started_at'] ) ? $cron_status['last_started_at'] : '—' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Last Run Imported', 'kreativ-font-ingestor' ); ?></th>
						<td><?php echo esc_html( (string) absint( $cron_status['imported'] ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Last Run Skipped', 'kreativ-font-ingestor' ); ?></th>
						<td><?php echo esc_html( (string) absint( $cron_status['skipped'] ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Last Run Errors', 'kreativ-font-ingestor' ); ?></th>
						<td><?php echo esc_html( (string) absint( $cron_status['error_count'] ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<hr />

			<h2><?php esc_html_e( 'Manual Import', 'kreativ-font-ingestor' ); ?></h2>
			<p><?php esc_html_e( 'Import the next batch of unprocessed fonts immediately.', 'kreativ-font-ingestor' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="kfi_manual_import" />
				<?php wp_nonce_field( 'kfi_manual_import' ); ?>
				<?php submit_button( __( 'Run Import Now', 'kreativ-font-ingestor' ), 'primary', 'submit', false ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Regenerate Imported Posts', 'kreativ-font-ingestor' ); ?></h2>
			<p><?php esc_html_e( 'Refresh existing imported posts with the latest enrichment data, related-font links, ZIP metadata, and featured images.', 'kreativ-font-ingestor' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px;">
				<input type="hidden" name="action" value="kfi_regenerate_posts" />
				<?php wp_nonce_field( 'kfi_regenerate_posts' ); ?>
				<label for="kfi-regenerate-post-id" style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Single Post ID', 'kreativ-font-ingestor' ); ?></label>
				<input id="kfi-regenerate-post-id" type="number" min="1" name="post_id" value="" />
				<?php submit_button( __( 'Regenerate This Post', 'kreativ-font-ingestor' ), 'secondary', 'submit', false, array( 'style' => 'margin-left:8px;' ) ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="kfi_regenerate_posts" />
				<?php wp_nonce_field( 'kfi_regenerate_posts' ); ?>
				<label for="kfi-regenerate-limit" style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Recent Posts Limit', 'kreativ-font-ingestor' ); ?></label>
				<input id="kfi-regenerate-limit" type="number" min="1" max="100" name="limit" value="10" />
				<?php submit_button( __( 'Regenerate Recent Imported Posts', 'kreativ-font-ingestor' ), 'secondary', 'submit', false, array( 'style' => 'margin-left:8px;' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Backfill Preview Assets', 'kreativ-font-ingestor' ); ?></h2>
			<p><?php esc_html_e( 'Generate managed preview files and optional webfont kits for existing imported fonts without rebuilding the full post content.', 'kreativ-font-ingestor' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px;">
				<input type="hidden" name="action" value="kfi_backfill_preview_assets" />
				<?php wp_nonce_field( 'kfi_backfill_preview_assets' ); ?>
				<label for="kfi-preview-backfill-post-id" style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Single Post ID', 'kreativ-font-ingestor' ); ?></label>
				<input id="kfi-preview-backfill-post-id" type="number" min="1" name="post_id" value="" />
				<?php submit_button( __( 'Backfill Preview Assets', 'kreativ-font-ingestor' ), 'secondary', 'submit', false, array( 'style' => 'margin-left:8px;' ) ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="kfi_backfill_preview_assets" />
				<?php wp_nonce_field( 'kfi_backfill_preview_assets' ); ?>
				<label for="kfi-preview-backfill-limit" style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Recent Posts Limit', 'kreativ-font-ingestor' ); ?></label>
				<input id="kfi-preview-backfill-limit" type="number" min="1" max="100" name="limit" value="25" />
				<?php submit_button( __( 'Backfill Recent Imported Fonts', 'kreativ-font-ingestor' ), 'secondary', 'submit', false, array( 'style' => 'margin-left:8px;' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Download Statistics', 'kreativ-font-ingestor' ); ?></h2>
			<div style="display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:12px;max-width:920px;margin-bottom:16px;">
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:14px;">
					<strong style="display:block;font-size:24px;"><?php echo esc_html( (string) $download_totals['total'] ); ?></strong>
					<span><?php esc_html_e( 'Total Downloads', 'kreativ-font-ingestor' ); ?></span>
				</div>
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:14px;">
					<strong style="display:block;font-size:24px;"><?php echo esc_html( (string) $download_totals['today'] ); ?></strong>
					<span><?php esc_html_e( 'Today', 'kreativ-font-ingestor' ); ?></span>
				</div>
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:14px;">
					<strong style="display:block;font-size:24px;"><?php echo esc_html( (string) $download_totals['week'] ); ?></strong>
					<span><?php esc_html_e( 'Last 7 Days', 'kreativ-font-ingestor' ); ?></span>
				</div>
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:14px;">
					<strong style="display:block;font-size:24px;"><?php echo esc_html( (string) $download_totals['month'] ); ?></strong>
					<span><?php esc_html_e( 'Last 30 Days', 'kreativ-font-ingestor' ); ?></span>
				</div>
			</div>
			<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:start;">
				<div>
					<h3><?php esc_html_e( 'Top Downloaded Fonts', 'kreativ-font-ingestor' ); ?></h3>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Font', 'kreativ-font-ingestor' ); ?></th>
								<th><?php esc_html_e( 'Downloads', 'kreativ-font-ingestor' ); ?></th>
								<th><?php esc_html_e( 'Last Download', 'kreativ-font-ingestor' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $top_downloads ) ) : ?>
								<tr><td colspan="3"><?php esc_html_e( 'No download data yet.', 'kreativ-font-ingestor' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $top_downloads as $row ) : ?>
									<tr>
										<td><a href="<?php echo esc_url( get_edit_post_link( $row['post_id'] ) ); ?>"><?php echo esc_html( $row['font_family'] ? $row['font_family'] : $row['title'] ); ?></a></td>
										<td><?php echo esc_html( (string) $row['download_count'] ); ?></td>
										<td><?php echo esc_html( $row['last_download'] ? $row['last_download'] : '—' ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
				<div>
					<h3><?php esc_html_e( 'Recent Downloads', 'kreativ-font-ingestor' ); ?></h3>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Font', 'kreativ-font-ingestor' ); ?></th>
								<th><?php esc_html_e( 'Downloaded At', 'kreativ-font-ingestor' ); ?></th>
								<th><?php esc_html_e( 'Referrer', 'kreativ-font-ingestor' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $recent_downloads ) ) : ?>
								<tr><td colspan="3"><?php esc_html_e( 'No tracked downloads yet.', 'kreativ-font-ingestor' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $recent_downloads as $row ) : ?>
									<tr>
										<td><a href="<?php echo esc_url( get_edit_post_link( absint( $row['post_id'] ) ) ); ?>"><?php echo esc_html( $row['font_family'] ); ?></a></td>
										<td><?php echo esc_html( sanitize_text_field( $row['downloaded_at'] ) ); ?></td>
										<td><?php echo esc_html( ! empty( $row['referrer_url'] ) ? wp_parse_url( $row['referrer_url'], PHP_URL_HOST ) : 'Direct' ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<hr />

			<h2><?php esc_html_e( 'Logs', 'kreativ-font-ingestor' ); ?></h2>
			<textarea class="large-text code" rows="18" readonly><?php echo esc_textarea( $logs ); ?></textarea>

			<hr />

			<div style="margin-top:16px;padding:16px 18px;background:#fff;border:1px solid #dcdcde;border-radius:12px;max-width:520px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Release Info', 'kreativ-font-ingestor' ); ?></h2>
				<p style="margin:0 0 8px;">
					<strong><?php esc_html_e( 'Plugin Version:', 'kreativ-font-ingestor' ); ?></strong>
					<?php echo esc_html( KFI_VERSION ); ?>
				</p>
				<p style="margin:0;color:#646970;">
					<?php esc_html_e( 'This version number is read from the installed plugin files.', 'kreativ-font-ingestor' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render preview debug data for imported font posts.
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function render_debug_meta_box( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$post_id     = absint( $post->ID );
		$font_family = get_post_meta( $post_id, '_kfi_font_family', true );

		if ( empty( $font_family ) ) {
			echo '<p>' . esc_html__( 'This post is not managed by Kreativ Free Fonts.', 'kreativ-font-ingestor' ) . '</p>';
			return;
		}

		$preview_url    = sanitize_text_field( get_post_meta( $post_id, '_kfi_preview_asset_url', true ) );
		$preview_path   = sanitize_text_field( get_post_meta( $post_id, '_kfi_preview_asset_path', true ) );
		$preview_status = sanitize_text_field( get_post_meta( $post_id, '_kfi_preview_asset_status', true ) );
		$source_file    = sanitize_text_field( get_post_meta( $post_id, '_kfi_preview_asset_source_file', true ) );
		$preview_format = sanitize_text_field( get_post_meta( $post_id, '_kfi_preview_asset_format', true ) );
		$webfont_url    = sanitize_text_field( get_post_meta( $post_id, '_kfi_webfont_zip_url', true ) );
		$webfont_name   = sanitize_text_field( get_post_meta( $post_id, '_kfi_webfont_zip_name', true ) );
		$webfont_size   = sanitize_text_field( get_post_meta( $post_id, '_kfi_webfont_zip_size_human', true ) );
		$file_exists    = $preview_path && file_exists( $preview_path );
		$rows           = array(
			__( 'Managed preview URL', 'kreativ-font-ingestor' )   => $preview_url ? $preview_url : '—',
			__( 'Managed preview path', 'kreativ-font-ingestor' )  => $preview_path ? $preview_path : '—',
			__( 'Managed preview status', 'kreativ-font-ingestor' ) => $preview_status ? $preview_status : '—',
			__( 'Preview source file', 'kreativ-font-ingestor' )   => $source_file ? $source_file : '—',
			__( 'Preview format', 'kreativ-font-ingestor' )        => $preview_format ? $preview_format : '—',
			__( 'File exists on disk', 'kreativ-font-ingestor' )   => $file_exists ? __( 'Yes', 'kreativ-font-ingestor' ) : __( 'No', 'kreativ-font-ingestor' ),
			__( 'Webfont kit URL', 'kreativ-font-ingestor' )       => $webfont_url ? $webfont_url : '—',
			__( 'Webfont kit name', 'kreativ-font-ingestor' )      => $webfont_name ? $webfont_name : '—',
			__( 'Webfont kit size', 'kreativ-font-ingestor' )      => $webfont_size ? $webfont_size : '—',
		);
		?>
		<div class="kfi-preview-debug">
			<?php foreach ( $rows as $label => $value ) : ?>
				<p style="margin:0 0 10px;">
					<strong style="display:block;"><?php echo esc_html( $label ); ?></strong>
					<span style="word-break:break-word;"><?php echo esc_html( $value ); ?></span>
				</p>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
