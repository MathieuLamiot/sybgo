<?php
/**
 * Sybgo - Since You've Been Gone
 *
 * @package Sybgo
 *
 * Plugin Name: Sybgo
 * Plugin URI: https://github.com/your-repo/sybgo
 * Description: Tracks meaningful WordPress events and sends weekly email digests. Since You've Been Gone - stay informed about what's happening on your site.
 * Version: 0.1.0
 * Author: MathieuLamiot
 * Author URI: https://mathieulamiot.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sybgo
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

declare(strict_types=1);

namespace Sybgo;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SYBGO_VERSION' ) ) {
	define( 'SYBGO_VERSION', '1.0.0' );
}
if ( ! defined( 'SYBGO_PLUGIN_FILE' ) ) {
	define( 'SYBGO_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'SYBGO_PLUGIN_DIR' ) ) {
	define( 'SYBGO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'SYBGO_PLUGIN_URL' ) ) {
	define( 'SYBGO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Require Composer autoloader if it exists.
if ( file_exists( SYBGO_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once SYBGO_PLUGIN_DIR . 'vendor/autoload.php';
}


/**
 * Main Sybgo Plugin Class.
 *
 * Initializes the Sybgo plugin and coordinates all subsystems.
 * This is the standalone plugin wrapper around the Sybgo library.
 *
 * @package Sybgo
 * @since   1.0.0
 */
class Sybgo {
	/**
	 * Singleton instance.
	 *
	 * @var Sybgo|null
	 */
	private static ?Sybgo $instance = null;

	/**
	 * Factory instance.
	 *
	 * @var Factory
	 */
	private Factory $factory;

	/**
	 * Get singleton instance.
	 *
	 * @return Sybgo
	 */
	public static function get_instance(): Sybgo {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor - private to enforce singleton.
	 */
	private function __construct() {
		$this->factory = new Factory( $this->get_library_config() );

		// Activation and Deactivation Hooks.
		register_activation_hook( SYBGO_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( SYBGO_PLUGIN_FILE, array( $this, 'deactivate' ) );

		// Initialize plugin.
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Get configuration for the library Factory.
	 *
	 * Bridges plugin settings (Settings_Page) to the library's config interface.
	 *
	 * @return array<string, mixed> Configuration array.
	 */
	private function get_library_config(): array {
		return array(
			'api_key_provider'        => function () {
				return Admin\Settings_Page::get_anthropic_api_key();
			},
			'email_settings_provider' => function () {
				$settings = get_option( Admin\Settings_Page::OPTION_NAME, array() );
				return array(
					'recipients'         => Admin\Settings_Page::get_recipients(),
					'from_name'          => $settings['from_name'] ?? get_bloginfo( 'name' ),
					'from_email'         => $settings['from_email'] ?? get_option( 'admin_email' ),
					'send_empty_reports' => $settings['send_empty_reports'] ?? false,
				);
			},
		);
	}

	/**
	 * Initialize plugin subsystems.
	 *
	 * @return void
	 */
	public function init(): void {
		// Initialize database.
		$this->factory->create_database_manager();

		// Initialize event tracking.
		$this->init_event_tracking();

		// Initialize extensibility API.
		$this->init_extensibility_api();

		// Initialize admin interface.
		if ( is_admin() ) {
			$this->init_admin();
		}

		// Initialize cron schedules.
		$this->init_cron_schedules();
	}

	/**
	 * Initialize event tracking system.
	 *
	 * @return void
	 */
	private function init_event_tracking(): void {
		// Initialize event tracker.
		$event_repo    = $this->factory->create_event_repository();
		$event_tracker = new Events\Event_Tracker( $event_repo );
		$event_tracker->init();

		// Store in factory for later use.
		$this->factory->set_event_tracker( $event_tracker );
	}

	/**
	 * Initialize extensibility API.
	 *
	 * @return void
	 */
	private function init_extensibility_api(): void {
		// Initialize API with event repository.
		$event_repo = $this->factory->create_event_repository();
		\sybgo_init_api( $event_repo );
	}

	/**
	 * Initialize admin interface.
	 *
	 * @return void
	 */
	private function init_admin(): void {
		// Initialize dashboard widget.
		$dashboard_widget = $this->create_dashboard_widget();
		$dashboard_widget->init();

		// Initialize settings page.
		$settings_page = $this->create_settings_page();
		$settings_page->init();

		// Initialize reports page.
		$reports_page = $this->create_reports_page();
		$reports_page->init();

		// Register manual cleanup handler.
		add_action( 'admin_post_sybgo_run_cleanup', array( $this, 'handle_manual_cleanup' ) );

		// Enqueue admin assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Create dashboard widget instance.
	 *
	 * @return Admin\Dashboard_Widget Dashboard widget instance.
	 */
	private function create_dashboard_widget(): Admin\Dashboard_Widget {
		$event_repo       = $this->factory->create_event_repository();
		$report_repo      = $this->factory->create_report_repository();
		$event_registry   = $this->factory->create_event_registry();
		$ai_summarizer    = $this->factory->create_ai_summarizer();
		$report_generator = new Reports\Report_Generator( $event_repo, $report_repo, $ai_summarizer );

		return new Admin\Dashboard_Widget(
			$event_repo,
			$report_repo,
			$report_generator,
			$ai_summarizer,
			$event_registry
		);
	}

	/**
	 * Create settings page instance.
	 *
	 * @return Admin\Settings_Page Settings page instance.
	 */
	private function create_settings_page(): Admin\Settings_Page {
		$event_registry = $this->factory->create_event_registry();
		$db_stats       = $this->factory->create_db_stats();

		return new Admin\Settings_Page( $event_registry, $db_stats );
	}

	/**
	 * Create reports page instance.
	 *
	 * @return Admin\Reports_Page Reports page instance.
	 */
	private function create_reports_page(): Admin\Reports_Page {
		$event_repo       = $this->factory->create_event_repository();
		$report_repo      = $this->factory->create_report_repository();
		$event_registry   = $this->factory->create_event_registry();
		$report_manager   = $this->factory->create_report_manager();
		$ai_summarizer    = $this->factory->create_ai_summarizer();
		$report_generator = new Reports\Report_Generator( $event_repo, $report_repo, $ai_summarizer );
		$email_manager    = $this->factory->create_email_manager();

		return new Admin\Reports_Page(
			$event_repo,
			$report_repo,
			$report_manager,
			$report_generator,
			$email_manager,
			$event_registry
		);
	}

	/**
	 * Get all cron hook names registered by the plugin.
	 *
	 * Single source of truth for cron hook names, used both when scheduling
	 * (init_cron_schedules, deactivate) and when cleaning up (Uninstaller).
	 *
	 * @return array<string> List of WP-Cron hook names.
	 * @since 1.0.0
	 */
	public static function get_cron_hooks(): array {
		return array(
			'sybgo_freeze_weekly_report',
			'sybgo_send_report_emails',
			'sybgo_cleanup_old_events',
			'sybgo_retry_failed_emails',
		);
	}

	/**
	 * Initialize cron schedules.
	 *
	 * @return void
	 */
	private function init_cron_schedules(): void {
		$hooks = self::get_cron_hooks();

		// Register custom cron intervals.
		add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );

		// Schedule weekly freeze (Sunday 23:55).
		if ( ! wp_next_scheduled( $hooks[0] ) ) {
			$next_sunday = strtotime( 'next Sunday 23:55' );
			wp_schedule_event( $next_sunday, 'weekly', $hooks[0] );
		}

		// Schedule weekly email (Monday 00:05).
		if ( ! wp_next_scheduled( $hooks[1] ) ) {
			$next_monday = strtotime( 'next Monday 00:05' );
			wp_schedule_event( $next_monday, 'weekly', $hooks[1] );
		}

		// Schedule daily cleanup (3am).
		if ( ! wp_next_scheduled( $hooks[2] ) ) {
			$next_3am = strtotime( 'tomorrow 3:00' );
			wp_schedule_event( $next_3am, 'daily', $hooks[2] );
		}

		// Schedule daily retry failed emails (9am).
		if ( ! wp_next_scheduled( $hooks[3] ) ) {
			$next_9am = strtotime( 'tomorrow 9:00' );
			wp_schedule_event( $next_9am, 'daily', $hooks[3] );
		}

		// Register cron callbacks.
		add_action( $hooks[0], array( $this, 'freeze_weekly_report_callback' ) );
		add_action( $hooks[1], array( $this, 'send_report_emails_callback' ) );
		add_action( $hooks[2], array( $this, 'cleanup_old_events_callback' ) );
		add_action( $hooks[3], array( $this, 'retry_failed_emails_callback' ) );
	}

	/**
	 * Add custom cron intervals.
	 *
	 * @param array<string, array<string, mixed>> $schedules Existing schedules.
	 * @return array<string, array<string, mixed>> Modified schedules.
	 */
	public function add_cron_intervals( array $schedules ): array {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => 604800, // 7 days in seconds.
				'display'  => esc_html__( 'Once Weekly', 'sybgo' ),
			);
		}
		return $schedules;
	}

	/**
	 * Cron callback: Freeze weekly report.
	 *
	 * @return void
	 */
	public function freeze_weekly_report_callback(): void {
		$report_manager = $this->factory->create_report_manager();
		$frozen_id      = $report_manager->freeze_current_report();

		if ( $frozen_id ) {
			Logger::info( sprintf( 'Weekly report #%d frozen successfully', $frozen_id ) );
		} else {
			Logger::info( 'No active report to freeze' );
		}
	}

	/**
	 * Cron callback: Send report emails.
	 *
	 * @return void
	 */
	public function send_report_emails_callback(): void {
		$report_repo   = $this->factory->create_report_repository();
		$email_manager = $this->factory->create_email_manager();

		// Get last frozen report.
		$last_frozen = $report_repo->get_last_frozen();

		if ( ! $last_frozen ) {
			return;
		}

		// Send email.
		$sent = $email_manager->send_report_email( $last_frozen['id'] );

		// Log result.
		if ( $sent ) {
			Logger::info( sprintf( 'Successfully sent weekly digest for report #%d', $last_frozen['id'] ) );
		} else {
			Logger::error( sprintf( 'Failed to send weekly digest for report #%d', $last_frozen['id'] ) );
		}
	}

	/**
	 * Cron callback: Cleanup old events.
	 *
	 * @return void
	 */
	public function cleanup_old_events_callback(): void {
		$db_manager = $this->factory->create_database_manager();
		$days       = Admin\Settings_Page::get_retention_days();
		$deleted    = $db_manager->cleanup_old_events( $days );

		// Log cleanup action.
		if ( $deleted > 0 ) {
			Logger::info( sprintf( 'Cleaned up %d rows (retention: %d days)', $deleted, $days ) );
		}
	}

	/**
	 * Handle manual cleanup form submission.
	 *
	 * Verifies nonce and capability, runs cleanup with the configured retention period,
	 * then redirects back to the settings page with the deletion count in the query string.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function handle_manual_cleanup(): void {
		if (
			! isset( $_POST['sybgo_cleanup_nonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['sybgo_cleanup_nonce'] ) ),
				'sybgo_run_cleanup'
			)
		) {
			wp_die( esc_html__( 'Security check failed.', 'sybgo' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'sybgo' ) );
		}

		$days       = Admin\Settings_Page::get_retention_days();
		$db_manager = $this->factory->create_database_manager();
		$deleted    = $db_manager->cleanup_old_events( $days );

		Logger::info( sprintf( 'Manual cleanup: deleted %d rows with %d-day retention', $deleted, $days ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'sybgo-settings',
					'cleanup-done' => $deleted,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Cron callback: Retry failed emails.
	 *
	 * @return void
	 */
	public function retry_failed_emails_callback(): void {
		$email_manager = $this->factory->create_email_manager();

		// Retry failed emails.
		$retried = $email_manager->retry_failed_emails();

		// Log result.
		if ( $retried > 0 ) {
			Logger::info( sprintf( 'Retried %d failed emails', $retried ) );
		}
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook ): void {
		// Enqueue only on our admin pages and dashboard.
		$our_pages = array( 'toplevel_page_sybgo-reports', 'settings_page_sybgo-settings', 'index.php' );

		if ( ! in_array( $hook, $our_pages, true ) ) {
			return;
		}

		// Enqueue CSS.
		wp_enqueue_style(
			'sybgo-admin',
			SYBGO_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			SYBGO_VERSION
		);

		// Enqueue JS.
		wp_enqueue_script(
			'sybgo-admin',
			SYBGO_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			SYBGO_VERSION,
			true
		);

		// Localize script with data.
		wp_localize_script(
			'sybgo-admin',
			'sybgoAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'sybgo_admin_nonce' ),
			)
		);
	}

	/**
	 * Activation Hook.
	 *
	 * @return void
	 */
	public function activate(): void {
		// Create database tables.
		$this->factory->create_database_manager();

		// Create initial active report.
		$report_repo = $this->factory->create_report_repository();
		$active      = $report_repo->get_active();

		if ( null === $active ) {
			$report_repo->create(
				array(
					'status'       => 'active',
					'period_start' => current_time( 'mysql' ),
				)
			);
		}

		// Set default options.
		if ( false === get_option( Admin\Settings_Page::LEGACY_OPTION_EMAIL_RECIPIENTS ) ) {
			update_option( Admin\Settings_Page::LEGACY_OPTION_EMAIL_RECIPIENTS, get_option( 'admin_email' ) );
		}

		// Flush rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Deactivation Hook.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		// Clear scheduled events.
		foreach ( self::get_cron_hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		// Flush rewrite rules.
		flush_rewrite_rules();
	}
}

// Initialize the plugin.
Sybgo::get_instance();
