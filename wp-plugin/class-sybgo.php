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
	 * @return void
	 */
	public function init(): void {
		// Initialize database.
		$this->factory->create_database_manager();

		// Initialize event tracking.
		$this->init_event_tracking();

		// Initialize extensibility API and WordPress 7 Ability API.
		$this->init_abilities();

		// Initialize admin interface.
		if ( is_admin() ) {
			$this->init_admin();
		}

		// Initialize cron schedules.
		$this->init_cron();
	}

	/**
	 * Initialize event tracking system.
	 *
	 * @return void
	 */
	private function init_event_tracking(): void {
		// Initialize event tracker.
		$event_repo      = $this->factory->create_event_repository();
		$aggregated_repo = $this->factory->create_aggregated_event_repository();
		$event_tracker   = new Events\Event_Tracker( $event_repo, $aggregated_repo );
		$event_tracker->init();

		// Store in factory for later use.
		$this->factory->set_event_tracker( $event_tracker );
	}

	/**
	 * Register all plugin cron events and wire their callbacks.
	 *
	 * Creates services via the factory, registers closures with Cron_Manager,
	 * then calls init() to schedule and wire everything.
	 *
	 * @return void
	 */
	private function init_cron(): void {
		$cron_manager   = new Cron_Manager();
		$report_manager = $this->factory->create_report_manager();
		$email_manager  = $this->factory->create_email_manager();
		$db_manager     = $this->factory->create_database_manager();

		$cron_manager->register(
			'sybgo_freeze_weekly_report',
			'weekly',
			static function () use ( $report_manager ): void {
				$report_manager->freeze_current_report();
			},
			'next Sunday 23:55'
		);

		$cron_manager->register(
			'sybgo_send_report_emails',
			'weekly',
			static function () use ( $report_manager, $email_manager ): void {
				$last_frozen = $report_manager->get_last_frozen_report();
				if ( ! $last_frozen ) {
					return;
				}
				// int cast: $wpdb returns column values as strings; send_report_email() is strictly typed — see #68.
				$email_manager->send_report_email( (int) $last_frozen['id'] );
			},
			'next Monday 00:05'
		);

		$cron_manager->register(
			'sybgo_cleanup_old_events',
			'daily',
			static function () use ( $db_manager ): void {
				$db_manager->cleanup_old_events( Admin\Settings_Page::get_retention_days() );
			},
			'tomorrow 3:00'
		);

		$cron_manager->register(
			'sybgo_retry_failed_emails',
			'daily',
			static function () use ( $email_manager ): void {
				$email_manager->retry_failed_emails();
			},
			'tomorrow 9:00'
		);

		$cron_manager->init();
	}

	/**
	 * Initialise the public extensibility API and register WordPress 7 abilities.
	 *
	 * @return void
	 */
	private function init_abilities(): void {
		// Initialise the public API so third-party plugins can track events.
		$event_repo = $this->factory->create_event_repository();
		\sybgo_init_api( $event_repo );

		// Guard: ability registration (and the __() calls inside it) must only
		// run on WordPress 7+.  On earlier versions wp_register_ability is
		// undefined, and evaluating __() args inside register() too early
		// (during plugins_loaded) triggers _doing_it_wrong() → trigger_error().
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$ability_manager = new Ability_Manager();
		$factory         = $this->factory;

		$ability_manager->register(
			'sybgo/generate-summary',
			array(
				'label'               => __( 'Generate Weekly Summary', 'sybgo' ),
				'description'         => __( 'Generates an AI-powered summary of the weekly site activity report.', 'sybgo' ),
				'category'            => 'sybgo',
				'execute_callback'    => static function () use ( $factory ): ?string {
					$ai_summarizer = $factory->create_ai_summarizer();
					if ( null === $ai_summarizer ) {
						return null;
					}
					$last_frozen = $factory->create_report_repository()->get_last_frozen();
					if ( ! $last_frozen ) {
						return null;
					}
					// Summary generation is handled via Report_Generator; stub for Ability API.
					return null;
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
			)
		);

		$ability_manager->register(
			'sybgo/track-events',
			array(
				'label'               => __( 'Track Site Events', 'sybgo' ),
				'description'         => __( 'Records WordPress site events for inclusion in the weekly digest.', 'sybgo' ),
				'category'            => 'sybgo',
				'execute_callback'    => static function () use ( $factory ): bool {
					return null !== $factory->get_event_tracker();
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
			)
		);

		$ability_manager->init();
	}

	/**
	 * Initialize admin interface.
	 *
	 * @return void
	 */
	private function init_admin(): void {
		$admin_manager = new Admin\Admin_Manager();

		$admin_manager->register_page( $this->create_dashboard_widget() );
		$admin_manager->register_page( $this->create_settings_page() );
		$admin_manager->register_page( $this->create_reports_page() );

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
	 * Create dashboard widget instance.
	 *
	 * @return Admin\Dashboard_Widget Dashboard widget instance.
	 */
	private function create_dashboard_widget(): Admin\Dashboard_Widget {
		$event_repo       = $this->factory->create_event_repository();
		$report_repo      = $this->factory->create_report_repository();
		$event_registry   = $this->factory->create_event_registry();
		$ai_summarizer    = $this->factory->create_ai_summarizer();
		$aggregated_repo  = $this->factory->create_aggregated_event_repository();
		$report_generator = new Reports\Report_Generator( $event_repo, $report_repo );

		return new Admin\Dashboard_Widget(
			$event_repo,
			$report_repo,
			$report_generator,
			$ai_summarizer,
			$event_registry,
			$aggregated_repo
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
		$report_generator = new Reports\Report_Generator( $event_repo, $report_repo );
		$email_manager    = $this->factory->create_email_manager();
		$aggregated_repo  = $this->factory->create_aggregated_event_repository();

		return new Admin\Reports_Page(
			$event_repo,
			$report_repo,
			$report_manager,
			$report_generator,
			$email_manager,
			$event_registry,
			$aggregated_repo,
			$ai_summarizer
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
		Cron_Manager::deactivate();

		// Flush rewrite rules.
		flush_rewrite_rules();
	}
}

// Initialize the plugin.
Sybgo::get_instance();
