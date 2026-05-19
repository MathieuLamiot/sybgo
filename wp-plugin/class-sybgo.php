<?php
/**
 * Sybgo - Since You've Been Gone
 *
 * @package Sybgo
 *
 * Plugin Name: Sybgo
 * Plugin URI: https://github.com/your-repo/sybgo
 * Description: Tracks meaningful WordPress events and sends weekly email digests. Since You've Been Gone - stay informed about what's happening on your site.
 * Version: 0.1.3
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
	 * Creates the three manager instances, boots all feature modules, then
	 * initialises each manager. Module boot() calls only register on the
	 * managers — they do not execute domain logic directly.
	 *
	 * @return void
	 */
	public function init(): void {
		// Initialize database.
		$this->factory->create_database_manager();

		$cron      = new Cron_Manager();
		$admin     = new Admin\Admin_Manager();
		$abilities = new Ability_Manager();

		foreach ( $this->build_modules( $cron, $admin, $abilities ) as $module ) {
			$module->boot();
		}

		// Defer Ability_Manager::init() to the 'init' action at priority 20
		// so that module boot() callbacks (which register abilities at priority 5
		// on 'init') have already run before the manager wires them into WP.
		add_action(
			'init',
			static function () use ( $abilities ): void {
				$abilities->init();
			},
			20
		);

		// Legacy init_*() sub-methods keep remaining behaviour until each
		// module's boot() is fully implemented (sub-issues #96–#99).
		// They are removed in sub-issue #100 once all modules are complete.
		// init_abilities() removed in sub-issue #98 (AI_Module owns generate-summary).
		if ( is_admin() ) {
			$this->init_admin();
		}
		$this->init_cron_schedules();
	}

	/**
	 * Build the list of feature modules.
	 *
	 * Each module receives only the deps it needs. The order determines boot()
	 * call order; for the scaffold phase all boot() methods are no-ops.
	 *
	 * @param Cron_Manager        $cron      Cron Manager instance.
	 * @param Admin\Admin_Manager $admin     Admin Manager instance.
	 * @param Ability_Manager     $abilities Ability Manager instance.
	 * @return Modules\Module_Interface[] Ordered list of feature modules.
	 */
	private function build_modules(
		Cron_Manager $cron,
		Admin\Admin_Manager $admin,
		Ability_Manager $abilities
	): array {
		return array(
			new Modules\Event_Module( $this->factory, $abilities ),
			new Modules\Report_Module( $this->factory, $cron, $admin ),
			new Modules\Email_Module( $this->factory, $cron ),
			new Modules\AI_Module( $this->factory, $abilities ),
			new Modules\Settings_Module( $this->factory, $cron, $admin ),
		);
	}

	/**
	 * Initialize admin interface via Admin_Manager.
	 *
	 * @return void
	 */
	private function init_admin(): void {
		$admin_manager = new Admin\Admin_Manager();

		// Dashboard_Widget and Reports_Page are now registered by Report_Module::boot().
		$admin_manager->register_page( $this->create_settings_page() );

		$factory = $this->factory;

		$admin_manager->register_cleanup_handler(
			static function () use ( $factory ): void {
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
				$db_manager = $factory->create_database_manager();
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
		);

		$admin_manager->register_asset_enqueuer(
			static function ( string $hook ): void {
				$our_pages = array( 'toplevel_page_sybgo-reports', 'settings_page_sybgo-settings', 'index.php' );

				if ( ! in_array( $hook, $our_pages, true ) ) {
					return;
				}

				wp_enqueue_style(
					'sybgo-admin',
					SYBGO_PLUGIN_URL . 'assets/css/admin.css',
					array(),
					SYBGO_VERSION
				);

				wp_enqueue_script(
					'sybgo-admin',
					SYBGO_PLUGIN_URL . 'assets/js/admin.js',
					array( 'jquery' ),
					SYBGO_VERSION,
					true
				);

				wp_localize_script(
					'sybgo-admin',
					'sybgoAdmin',
					array(
						'ajaxUrl' => admin_url( 'admin-ajax.php' ),
						'nonce'   => wp_create_nonce( 'sybgo_admin_nonce' ),
					)
				);
			}
		);

		$admin_manager->init();
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

		// sybgo_freeze_weekly_report is now registered by Report_Module::boot().
		// sybgo_send_report_emails and sybgo_retry_failed_emails are now registered
		// by Email_Module::boot() via CronManager.

		// Schedule daily cleanup (3am). Moved to Settings_Module in #99.
		if ( ! wp_next_scheduled( $hooks[2] ) ) {
			$next_3am = strtotime( 'tomorrow 3:00' );
			wp_schedule_event( $next_3am, 'daily', $hooks[2] );
		}

		// Register remaining cron callback (cleanup; email callbacks handled by Email_Module).
		add_action( $hooks[2], array( $this, 'cleanup_old_events_callback' ) );
	}

	/**
	 * Cron callback: Cleanup old events.
	 *
	 * Moved to Settings_Module::cleanup_old_events_callback() in #99.
	 * Kept here temporarily until Settings_Module::boot() is wired.
	 *
	 * @return void
	 */
	public function cleanup_old_events_callback(): void {
		$db_manager = $this->factory->create_database_manager();
		$days       = Admin\Settings_Page::get_retention_days();
		$deleted    = $db_manager->cleanup_old_events( $days );

		if ( $deleted > 0 ) {
			Logger::info( sprintf( 'Cleaned up %d rows (retention: %d days)', $deleted, $days ) );
		}
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
