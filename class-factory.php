<?php
/**
 * Factory class file.
 *
 * This file defines the Factory class, responsible for creating and managing objects.
 *
 * @package Rocket\Sybgo
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Rocket\Sybgo;

// Require database classes.
require_once SYBGO_PLUGIN_DIR . 'database/class-databasemanager.php';
require_once SYBGO_PLUGIN_DIR . 'database/class-event-repository.php';
require_once SYBGO_PLUGIN_DIR . 'database/class-report-repository.php';

use Rocket\Sybgo\Database\DatabaseManager;
use Rocket\Sybgo\Database\Event_Repository;
use Rocket\Sybgo\Database\Report_Repository;

/**
 * Factory class.
 *
 * This class provides methods for creating and managing singleton instances.
 *
 * @package Rocket\Sybgo
 * @since   1.0.0
 */
class Factory {
	/**
	 * Database manager instance.
	 *
	 * @var DatabaseManager|null
	 */
	private static ?DatabaseManager $db_manager_instance = null;

	/**
	 * Event repository instance.
	 *
	 * @var Event_Repository|null
	 */
	private static ?Event_Repository $event_repo_instance = null;

	/**
	 * Report repository instance.
	 *
	 * @var Report_Repository|null
	 */
	private static ?Report_Repository $report_repo_instance = null;

	/**
	 * Event tracker instance.
	 *
	 * @var object|null
	 */
	private static ?object $event_tracker_instance = null;

	/**
	 * Report manager instance.
	 *
	 * @var object|null
	 */
	private static ?object $report_manager_instance = null;

	/**
	 * Dashboard widget instance.
	 *
	 * @var object|null
	 */
	private static ?object $dashboard_widget_instance = null;

	/**
	 * Settings page instance.
	 *
	 * @var object|null
	 */
	private static ?object $settings_page_instance = null;

	/**
	 * Reports page instance.
	 *
	 * @var object|null
	 */
	private static ?object $reports_page_instance = null;

	/**
	 * Email manager instance.
	 *
	 * @var object|null
	 */
	private static ?object $email_manager_instance = null;

	/**
	 * Create a database manager instance.
	 *
	 * @return DatabaseManager The database manager instance.
	 */
	public function create_database_manager(): DatabaseManager {
		if ( null === self::$db_manager_instance ) {
			self::$db_manager_instance = new DatabaseManager();
		}
		return self::$db_manager_instance;
	}

	/**
	 * Create event repository instance.
	 *
	 * @return Event_Repository The event repository instance.
	 */
	public function create_event_repository(): Event_Repository {
		if ( null === self::$event_repo_instance ) {
			$db_manager = $this->create_database_manager();
			$tables     = $db_manager->get_table_names();
			self::$event_repo_instance = new Event_Repository( $tables['events'] );
		}
		return self::$event_repo_instance;
	}

	/**
	 * Create report repository instance.
	 *
	 * @return Report_Repository The report repository instance.
	 */
	public function create_report_repository(): Report_Repository {
		if ( null === self::$report_repo_instance ) {
			$db_manager = $this->create_database_manager();
			$tables     = $db_manager->get_table_names();
			self::$report_repo_instance = new Report_Repository( $tables['reports'] );
		}
		return self::$report_repo_instance;
	}

	/**
	 * Set event tracker instance.
	 *
	 * @param object $event_tracker Event tracker instance.
	 * @return void
	 */
	public function set_event_tracker( object $event_tracker ): void {
		self::$event_tracker_instance = $event_tracker;
	}

	/**
	 * Get event tracker instance.
	 *
	 * @return object|null Event tracker instance or null.
	 */
	public function get_event_tracker(): ?object {
		return self::$event_tracker_instance;
	}

	/**
	 * Create report manager instance.
	 *
	 * @return object Report manager instance.
	 */
	public function create_report_manager(): object {
		if ( null === self::$report_manager_instance ) {
			require_once SYBGO_PLUGIN_DIR . 'reports/class-report-generator.php';
			require_once SYBGO_PLUGIN_DIR . 'reports/class-report-manager.php';

			$event_repo  = $this->create_event_repository();
			$report_repo = $this->create_report_repository();

			// Create generator.
			$generator = new \Rocket\Sybgo\Reports\Report_Generator( $event_repo, $report_repo );

			// Create manager.
			self::$report_manager_instance = new \Rocket\Sybgo\Reports\Report_Manager(
				$event_repo,
				$report_repo,
				$generator
			);
		}

		return self::$report_manager_instance;
	}

	/**
	 * Create dashboard widget instance.
	 *
	 * @return object Dashboard widget instance.
	 */
	public function create_dashboard_widget(): object {
		if ( null === self::$dashboard_widget_instance ) {
			require_once SYBGO_PLUGIN_DIR . 'admin/class-dashboard-widget.php';

			$event_repo       = $this->create_event_repository();
			$report_repo      = $this->create_report_repository();
			$report_manager   = $this->create_report_manager();
			$report_generator = new \Rocket\Sybgo\Reports\Report_Generator( $event_repo, $report_repo );

			self::$dashboard_widget_instance = new \Rocket\Sybgo\Admin\Dashboard_Widget(
				$event_repo,
				$report_repo,
				$report_generator
			);
		}

		return self::$dashboard_widget_instance;
	}

	/**
	 * Create settings page instance.
	 *
	 * @return object Settings page instance.
	 */
	public function create_settings_page(): object {
		if ( null === self::$settings_page_instance ) {
			require_once SYBGO_PLUGIN_DIR . 'admin/class-settings-page.php';

			self::$settings_page_instance = new \Rocket\Sybgo\Admin\Settings_Page();
		}

		return self::$settings_page_instance;
	}

	/**
	 * Create reports page instance.
	 *
	 * @return object Reports page instance.
	 */
	public function create_reports_page(): object {
		if ( null === self::$reports_page_instance ) {
			require_once SYBGO_PLUGIN_DIR . 'admin/class-reports-page.php';

			$event_repo       = $this->create_event_repository();
			$report_repo      = $this->create_report_repository();
			$report_manager   = $this->create_report_manager();
			$report_generator = new \Rocket\Sybgo\Reports\Report_Generator( $event_repo, $report_repo );
			$email_manager    = $this->create_email_manager();

			self::$reports_page_instance = new \Rocket\Sybgo\Admin\Reports_Page(
				$event_repo,
				$report_repo,
				$report_manager,
				$report_generator,
				$email_manager
			);
		}

		return self::$reports_page_instance;
	}

	/**
	 * Create email manager instance.
	 *
	 * @return object Email manager instance.
	 */
	public function create_email_manager(): object {
		if ( null === self::$email_manager_instance ) {
			require_once SYBGO_PLUGIN_DIR . 'email/class-email-template.php';
			require_once SYBGO_PLUGIN_DIR . 'email/class-email-manager.php';

			$report_repo    = $this->create_report_repository();
			$email_template = new \Rocket\Sybgo\Email\Email_Template();

			self::$email_manager_instance = new \Rocket\Sybgo\Email\Email_Manager(
				$report_repo,
				$email_template
			);
		}

		return self::$email_manager_instance;
	}

	/**
	 * Retrieve the WordPress filesystem object.
	 *
	 * @return \WP_Filesystem_Base|null The WordPress filesystem object.
	 */
	public static function get_filesystem(): ?\WP_Filesystem_Base {
		global $wp_filesystem;

		if ( ! is_object( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return $wp_filesystem;
	}
}

