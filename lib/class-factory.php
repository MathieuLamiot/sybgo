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
require_once __DIR__ . '/database/class-databasemanager.php';
require_once __DIR__ . '/database/class-event-repository.php';
require_once __DIR__ . '/database/class-report-repository.php';
require_once __DIR__ . '/events/class-event-registry.php';

use Rocket\Sybgo\Database\DatabaseManager;
use Rocket\Sybgo\Database\Event_Repository;
use Rocket\Sybgo\Database\Report_Repository;
use Rocket\Sybgo\Events\Event_Registry;

/**
 * Factory class.
 *
 * This class provides methods for creating and managing singleton instances
 * of the Sybgo library services.
 *
 * Accepts a config array to decouple from plugin-specific settings:
 * - 'api_key_provider'        => callable returning the Anthropic API key string.
 * - 'email_settings_provider' => callable returning an email settings array with keys:
 *                                 'recipients', 'from_name', 'from_email', 'send_empty_reports'.
 *
 * @package Rocket\Sybgo
 * @since   1.0.0
 */
class Factory {
	/**
	 * Configuration array.
	 *
	 * @var array
	 */
	private array $config;

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
	 * Email manager instance.
	 *
	 * @var object|null
	 */
	private static ?object $email_manager_instance = null;

	/**
	 * Event registry instance.
	 *
	 * @var Event_Registry|null
	 */
	private static ?Event_Registry $event_registry_instance = null;

	/**
	 * Constructor.
	 *
	 * @param array $config Configuration array with keys:
	 *                      - 'api_key_provider'        => callable returning API key string.
	 *                      - 'email_settings_provider' => callable returning email settings array.
	 */
	public function __construct( array $config = array() ) {
		$defaults = array(
			'api_key_provider'        => function () {
				return '';
			},
			'email_settings_provider' => function () {
				return array(
					'recipients'         => array( get_option( 'admin_email' ) ),
					'from_name'          => get_bloginfo( 'name' ),
					'from_email'         => get_option( 'admin_email' ),
					'send_empty_reports' => false,
				);
			},
		);

		$this->config = array_merge( $defaults, $config );
	}

	/**
	 * Get configuration value.
	 *
	 * @param string $key Configuration key.
	 * @return mixed Configuration value or null.
	 */
	public function get_config( string $key ) {
		return $this->config[ $key ] ?? null;
	}

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
			$db_manager                = $this->create_database_manager();
			$tables                    = $db_manager->get_table_names();
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
			$db_manager                 = $this->create_database_manager();
			$tables                     = $db_manager->get_table_names();
			self::$report_repo_instance = new Report_Repository( $tables['reports'] );
		}
		return self::$report_repo_instance;
	}

	/**
	 * Create event registry instance.
	 *
	 * @return Event_Registry The event registry instance.
	 */
	public function create_event_registry(): Event_Registry {
		if ( null === self::$event_registry_instance ) {
			self::$event_registry_instance = new Event_Registry();
		}
		return self::$event_registry_instance;
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
	 * Create AI summarizer instance.
	 *
	 * @return \Rocket\Sybgo\AI\AI_Summarizer AI summarizer instance.
	 */
	public function create_ai_summarizer(): \Rocket\Sybgo\AI\AI_Summarizer {
		require_once __DIR__ . '/ai/class-ai-summarizer.php';

		$report_repo    = $this->create_report_repository();
		$event_registry = $this->create_event_registry();

		return new \Rocket\Sybgo\AI\AI_Summarizer(
			$report_repo,
			$event_registry,
			$this->config['api_key_provider']
		);
	}

	/**
	 * Create report manager instance.
	 *
	 * @return object Report manager instance.
	 */
	public function create_report_manager(): object {
		if ( null === self::$report_manager_instance ) {
			require_once __DIR__ . '/reports/class-report-generator.php';
			require_once __DIR__ . '/reports/class-report-manager.php';

			$event_repo    = $this->create_event_repository();
			$report_repo   = $this->create_report_repository();
			$ai_summarizer = $this->create_ai_summarizer();

			// Create generator.
			$generator = new \Rocket\Sybgo\Reports\Report_Generator( $event_repo, $report_repo, $ai_summarizer );

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
	 * Create email manager instance.
	 *
	 * @return object Email manager instance.
	 */
	public function create_email_manager(): object {
		if ( null === self::$email_manager_instance ) {
			require_once __DIR__ . '/email/class-email-template.php';
			require_once __DIR__ . '/email/class-email-manager.php';

			$report_repo    = $this->create_report_repository();
			$event_registry = $this->create_event_registry();
			$email_template = new \Rocket\Sybgo\Email\Email_Template( $event_registry );

			self::$email_manager_instance = new \Rocket\Sybgo\Email\Email_Manager(
				$report_repo,
				$email_template,
				$this->config['email_settings_provider']
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
