<?php
/**
 * Uninstaller class file.
 *
 * Handles cleanup of all plugin-created data when the plugin is uninstalled.
 *
 * @package Sybgo\Admin
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\Admin;

use Sybgo\Database\DatabaseManager;
use Sybgo\Sybgo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uninstaller class.
 *
 * Removes all data created by the plugin: database tables, scheduled cron events,
 * and WordPress options. Called from uninstall.php when the plugin is deleted.
 *
 * @package Sybgo\Admin
 * @since   1.0.0
 */
class Uninstaller {

	/**
	 * Run the full uninstall cleanup.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->drop_tables();
		$this->clear_cron_hooks();
		$this->delete_options();
	}

	/**
	 * Drop all plugin database tables.
	 *
	 * Table names are sourced from DatabaseManager to avoid duplication.
	 *
	 * @return void
	 */
	public function drop_tables(): void {
		global $wpdb;

		foreach ( DatabaseManager::get_table_names() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
	}

	/**
	 * Clear all plugin-registered cron hooks.
	 *
	 * Hook names are sourced from the Sybgo class to avoid duplication.
	 *
	 * @return void
	 */
	public function clear_cron_hooks(): void {
		foreach ( Sybgo::get_cron_hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Delete all plugin WordPress options.
	 *
	 * Option names are sourced from Settings_Page to avoid duplication.
	 *
	 * @return void
	 */
	public function delete_options(): void {
		foreach ( Settings_Page::get_option_names() as $option ) {
			delete_option( $option );
		}
	}
}
