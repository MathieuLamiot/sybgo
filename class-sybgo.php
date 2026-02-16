<?php
/**
 * Sybgo - Since You've Been Gone
 *
 * @package Rocket\Sybgo
 *
 * Plugin Name: Sybgo - Activity Digest
 * Plugin URI: https://github.com/your-repo/sybgo
 * Description: Tracks meaningful WordPress events and sends weekly email digests. Since You've Been Gone - stay informed about what's happening on your site.
 * Version: 1.0.0
 * Author: GroupOne
 * Author URI: https://groupone.dev
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sybgo
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

declare(strict_types=1);

namespace Rocket\Sybgo;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SYBGO_VERSION', '1.0.0' );
define( 'SYBGO_PLUGIN_FILE', __FILE__ );
define( 'SYBGO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SYBGO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Require Composer autoloader if it exists.
if ( file_exists( SYBGO_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once SYBGO_PLUGIN_DIR . 'vendor/autoload.php';
}

// Require Factory.
require_once SYBGO_PLUGIN_DIR . 'class-factory.php';

/**
 * Main Sybgo Plugin Class.
 *
 * Initializes the Sybgo plugin and coordinates all subsystems.
 *
 * @package Rocket\Sybgo
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
		$this->factory = new Factory();

		// Activation and Deactivation Hooks.
		register_activation_hook( SYBGO_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( SYBGO_PLUGIN_FILE, array( $this, 'deactivate' ) );

		// Initialize plugin.
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Initialize plugin subsystems.
	 *
	 * @return void
	 */
	public function init(): void {
		// Load text domain for translations.
		load_plugin_textdomain( 'sybgo', false, dirname( plugin_basename( SYBGO_PLUGIN_FILE ) ) . '/languages' );

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
		// Load Event Tracker.
		require_once SYBGO_PLUGIN_DIR . 'events/class-event-tracker.php';

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
		// Load API functions.
		require_once SYBGO_PLUGIN_DIR . 'api/functions.php';

		// Initialize API with event repository.
		$event_repo = $this->factory->create_event_repository();
		sybgo_init_api( $event_repo );
	}

	/**
	 * Initialize admin interface.
	 *
	 * @return void
	 */
	private function init_admin(): void {
		// Initialize dashboard widget.
		$dashboard_widget = $this->factory->create_dashboard_widget();
		$dashboard_widget->init();

		// Initialize settings page.
		$settings_page = $this->factory->create_settings_page();
		$settings_page->init();

		// Initialize reports page.
		$reports_page = $this->factory->create_reports_page();
		$reports_page->init();

		// Enqueue admin assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Initialize cron schedules.
	 *
	 * @return void
	 */
	private function init_cron_schedules(): void {
		// Register custom cron intervals.
		add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );

		// Schedule weekly freeze (Sunday 23:55).
		if ( ! wp_next_scheduled( 'sybgo_freeze_weekly_report' ) ) {
			$next_sunday = strtotime( 'next Sunday 23:55' );
			wp_schedule_event( $next_sunday, 'weekly', 'sybgo_freeze_weekly_report' );
		}

		// Schedule weekly email (Monday 00:05).
		if ( ! wp_next_scheduled( 'sybgo_send_report_emails' ) ) {
			$next_monday = strtotime( 'next Monday 00:05' );
			wp_schedule_event( $next_monday, 'weekly', 'sybgo_send_report_emails' );
		}

		// Schedule daily cleanup (3am).
		if ( ! wp_next_scheduled( 'sybgo_cleanup_old_events' ) ) {
			$next_3am = strtotime( 'tomorrow 3:00' );
			wp_schedule_event( $next_3am, 'daily', 'sybgo_cleanup_old_events' );
		}

		// Schedule daily retry failed emails (9am).
		if ( ! wp_next_scheduled( 'sybgo_retry_failed_emails' ) ) {
			$next_9am = strtotime( 'tomorrow 9:00' );
			wp_schedule_event( $next_9am, 'daily', 'sybgo_retry_failed_emails' );
		}

		// Register cron callbacks.
		add_action( 'sybgo_freeze_weekly_report', array( $this, 'freeze_weekly_report_callback' ) );
		add_action( 'sybgo_send_report_emails', array( $this, 'send_report_emails_callback' ) );
		add_action( 'sybgo_cleanup_old_events', array( $this, 'cleanup_old_events_callback' ) );
		add_action( 'sybgo_retry_failed_emails', array( $this, 'retry_failed_emails_callback' ) );
	}

	/**
	 * Add custom cron intervals.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
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
			error_log( sprintf( 'Sybgo: Weekly report #%d frozen successfully', $frozen_id ) );
		} else {
			error_log( 'Sybgo: No active report to freeze' );
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
			error_log( sprintf( 'Sybgo: Successfully sent weekly digest for report #%d', $last_frozen['id'] ) );
		} else {
			error_log( sprintf( 'Sybgo: Failed to send weekly digest for report #%d', $last_frozen['id'] ) );
		}
	}

	/**
	 * Cron callback: Cleanup old events.
	 *
	 * @return void
	 */
	public function cleanup_old_events_callback(): void {
		$db_manager = $this->factory->create_database_manager();
		$deleted    = $db_manager->cleanup_old_events();

		// Log cleanup action.
		if ( $deleted > 0 ) {
			error_log( sprintf( 'Sybgo: Cleaned up %d old events', $deleted ) );
		}
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
			error_log( sprintf( 'Sybgo: Retried %d failed emails', $retried ) );
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
		if ( false === get_option( 'sybgo_email_recipients' ) ) {
			update_option( 'sybgo_email_recipients', get_option( 'admin_email' ) );
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
		wp_clear_scheduled_hook( 'sybgo_freeze_weekly_report' );
		wp_clear_scheduled_hook( 'sybgo_send_report_emails' );
		wp_clear_scheduled_hook( 'sybgo_cleanup_old_events' );
		wp_clear_scheduled_hook( 'sybgo_retry_failed_emails' );

		// Flush rewrite rules.
		flush_rewrite_rules();
	}
}

// Initialize the plugin.
Sybgo::get_instance();
