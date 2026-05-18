<?php
/**
 * Cron Manager class file.
 *
 * Pure WP-Cron registration utility. Has zero knowledge of functional classes.
 * Callers register hooks via register(), then call init() to schedule and wire them.
 *
 * @package Sybgo
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cron Manager.
 *
 * Registers custom cron intervals, schedules plugin events, and wires their
 * callbacks. All wiring declarations live in the caller (Sybgo::init_cron()).
 *
 * @package Sybgo
 * @since   1.0.0
 */
class Cron_Manager {

	/**
	 * Pending registrations.
	 *
	 * Each entry: [ 'hook' => string, 'schedule' => string,
	 *               'callback' => callable, 'first_run_expr' => string ]
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $registrations = array();

	/**
	 * All WP-Cron hook names registered by the plugin.
	 *
	 * Single source of truth used by init(), deactivate(), and Uninstaller.
	 *
	 * @return array<string>
	 */
	public static function get_hooks(): array {
		return array(
			'sybgo_freeze_weekly_report',
			'sybgo_send_report_emails',
			'sybgo_cleanup_old_events',
			'sybgo_retry_failed_emails',
		);
	}

	/**
	 * Accumulate a cron registration.
	 *
	 * @param string   $hook            WP action hook name.
	 * @param string   $schedule        Cron schedule name (e.g. 'weekly', 'daily').
	 * @param callable $callback        Callback to attach to the hook.
	 * @param string   $first_run_expr  strtotime-compatible expression for the first run time.
	 * @return void
	 */
	public function register( string $hook, string $schedule, callable $callback, string $first_run_expr ): void {
		$this->registrations[] = array(
			'hook'           => $hook,
			'schedule'       => $schedule,
			'callback'       => $callback,
			'first_run_expr' => $first_run_expr,
		);
	}

	/**
	 * Register the custom 'weekly' cron interval.
	 *
	 * @param array<string, array<string, mixed>> $schedules Existing schedules.
	 * @return array<string, array<string, mixed>>
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
	 * Schedule all registered cron events and wire their callbacks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );

		foreach ( $this->registrations as $reg ) {
			if ( ! wp_next_scheduled( $reg['hook'] ) ) {
				wp_schedule_event( strtotime( $reg['first_run_expr'] ), $reg['schedule'], $reg['hook'] );
			}
			add_action( $reg['hook'], $reg['callback'] );
		}
	}

	/**
	 * Clear all scheduled cron events.
	 *
	 * Static so it can be called from Sybgo::deactivate() and Uninstaller
	 * without requiring a fully-constructed instance.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		foreach ( self::get_hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
