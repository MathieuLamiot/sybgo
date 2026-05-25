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

// Bootstrap the MCP Adapter library. WP_MCP_AUTOLOAD=false tells its Autoloader to skip
// looking for its own vendor/autoload.php — Sybgo's Composer autoloader already covers all classes.
if ( ! defined( 'WP_MCP_AUTOLOAD' ) ) {
	define( 'WP_MCP_AUTOLOAD', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Third-party constant for wordpress/mcp-adapter Autoloader.
}
if ( file_exists( SYBGO_PLUGIN_DIR . 'vendor/wordpress/mcp-adapter/mcp-adapter.php' ) ) {
	require_once SYBGO_PLUGIN_DIR . 'vendor/wordpress/mcp-adapter/mcp-adapter.php';
}

add_action(
	'rest_api_init',
	static function (): void {
		$adapter     = \WP\MCP\Core\McpAdapter::instance();
		$all_servers = array_keys( $adapter->get_servers() );
		$has_server  = null !== $adapter->get_server( 'mcp-adapter-default-server' );
		MCP\Auth\Mcp_Logger::log(
			'BOOT',
			'rest_api_init@20 — adapter state',
			array(
				'wp_register_ability' => function_exists( 'wp_register_ability' ) ? 'yes' : 'no',
				'servers'             => $all_servers,
				'default_server'      => $has_server ? 'yes' : 'no',
			)
		);
	},
	20
);


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

		// Bootstrap the MCP OAuth layer (JWT façade over Application Passwords).
		MCP\Auth\Mcp_Auth::boot();

		// Suppress the default server so we can recreate it with our JWT permission callback.
		add_filter( 'mcp_adapter_create_default_server', '__return_false' );

		// Register the default abilities (discover, get-info, execute) that the
		// default server would normally wire up, since we suppress DefaultServerFactory.
		add_action( 'wp_abilities_api_categories_init', array( \WP\MCP\Core\McpAdapter::instance(), 'register_default_category' ) );
		add_action( 'wp_abilities_api_init', array( \WP\MCP\Core\McpAdapter::instance(), 'register_default_abilities' ) );

		add_action(
			'mcp_adapter_init',
			static function ( \WP\MCP\Core\McpAdapter $adapter ): void {
				MCP\Auth\Mcp_Logger::log( 'BOOT', 'mcp_adapter_init fired — creating server' );
				$result = $adapter->create_server(
					'mcp-adapter-default-server',
					'mcp',
					'mcp-adapter-default-server',
					'MCP Adapter Default Server',
					'Default MCP server for WordPress abilities discovery and execution',
					'v1.0.0',
					array( \WP\MCP\Transport\HttpTransport::class ),
					\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
					\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
					array(
						'mcp-adapter/discover-abilities',
						'mcp-adapter/get-ability-info',
						'mcp-adapter/execute-ability',
					),
					array(),
					array(),
					static function ( \WP_REST_Request $request ) {
						return MCP\Auth\Request_Validator::validate_request( $request );
					}
				);
				if ( is_wp_error( $result ) ) {
					MCP\Auth\Mcp_Logger::log(
						'BOOT',
						'create_server failed',
						array(
							'error_code'    => $result->get_error_code(),
							'error_message' => $result->get_error_message(),
						)
					);
				} else {
					MCP\Auth\Mcp_Logger::log( 'BOOT', 'create_server succeeded' );
				}
			}
		);
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

		// Wire abilities onto the correct WP Abilities API hooks.
		// Modules populate $abilities via register() on 'init@5'; both WP abilities
		// actions fire after 'init', so the cache is populated in time.
		$abilities->init();

		// Wire all registered cron events (schedule + add_action callbacks).
		// Modules declare their crons via Cron_Manager::register() in boot();
		// this single init() call schedules and wires all of them.
		$cron->init();

		// Wire all registered admin pages and hook callbacks.
		// Modules declare pages/handlers via Admin_Manager::register_*() in boot();
		// this init() call initialises each page and wires enqueuer + cleanup hooks.
		if ( is_admin() ) {
			$admin->init();
		}
	}

	/**
	 * Build the list of feature modules.
	 *
	 * Each module receives only the deps it needs. The order determines boot()
	 * call order; Event_Module must boot first so the Event_Tracker is available
	 * before other modules may depend on it.
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

		// Initialise the MCP OAuth layer (generates JWT secret, registers rewrite rules).
		MCP\Auth\Mcp_Auth::on_activation();

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
		foreach ( Cron_Manager::get_hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		// Flush rewrite rules.
		flush_rewrite_rules();
	}
}

// Initialize the plugin.
Sybgo::get_instance();
