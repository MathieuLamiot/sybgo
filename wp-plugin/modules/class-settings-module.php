<?php
/**
 * Settings Module class file.
 *
 * Owns all WordPress integration wiring for the settings domain:
 * registers the Settings_Page, the admin asset enqueuer, the manual
 * cleanup form handler, and the sybgo_cleanup_old_events cron callback.
 *
 * @package Sybgo\Modules
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\Modules;

use Sybgo\Admin\Admin_Manager;
use Sybgo\Admin\Settings_Page;
use Sybgo\Cron_Manager;
use Sybgo\Factory;
use Sybgo\Logger;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings Module.
 *
 * Responsible for the settings admin page, asset enqueueing, manual cleanup,
 * and the daily event-cleanup cron event.
 *
 * @since 1.0.0
 */
class Settings_Module implements Module_Interface {

	/**
	 * Factory instance.
	 *
	 * @var Factory
	 */
	private Factory $factory;

	/**
	 * Cron Manager instance.
	 *
	 * @var Cron_Manager
	 */
	private Cron_Manager $cron;

	/**
	 * Admin Manager instance.
	 *
	 * @var Admin_Manager
	 */
	private Admin_Manager $admin;

	/**
	 * Constructor.
	 *
	 * @param Factory       $factory Factory instance.
	 * @param Cron_Manager  $cron    Cron Manager instance.
	 * @param Admin_Manager $admin   Admin Manager instance.
	 */
	public function __construct( Factory $factory, Cron_Manager $cron, Admin_Manager $admin ) {
		$this->factory = $factory;
		$this->cron    = $cron;
		$this->admin   = $admin;
	}

	/**
	 * Register settings hooks, admin pages, and cron events.
	 *
	 * Registers Settings_Page, the cleanup form handler, and the asset
	 * enqueuer with Admin_Manager. Also registers the cleanup cron event
	 * via Cron_Manager so it is scheduled when Cron_Manager::init() runs.
	 *
	 * @return void
	 */
	public function boot(): void {
		$event_registry = $this->factory->create_event_registry();
		$db_stats       = $this->factory->create_db_stats();

		$this->admin->register_page( new Settings_Page( $event_registry, $db_stats ) );
		$this->admin->register_cleanup_handler( array( $this, 'handle_manual_cleanup' ) );
		$this->admin->register_asset_enqueuer( array( $this, 'enqueue_admin_assets' ) );

		$this->cron->register(
			'sybgo_cleanup_old_events',
			'daily',
			array( $this, 'cleanup_old_events_callback' ),
			'tomorrow 3:00'
		);
	}

	/**
	 * Cron callback: delete events older than the configured retention period.
	 *
	 * @return void
	 */
	public function cleanup_old_events_callback(): void {
		$db_manager = $this->factory->create_database_manager();
		$days       = Settings_Page::get_retention_days();
		$deleted    = $db_manager->cleanup_old_events( $days );

		if ( $deleted > 0 ) {
			Logger::info( sprintf( 'Cleaned up %d rows (retention: %d days)', $deleted, $days ) );
		}
	}

	/**
	 * Admin POST handler: run manual cleanup from the settings page form.
	 *
	 * Verifies the nonce and capability, deletes old events, then redirects
	 * back to the settings page with a query-string result count.
	 *
	 * @return void
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

		$days       = Settings_Page::get_retention_days();
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
	 * Enqueue admin CSS and JS for sybgo admin pages.
	 *
	 * @param string $hook Current admin page hook string.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook ): void {
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
}
