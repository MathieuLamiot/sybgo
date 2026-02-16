<?php
/**
 * Factory class file.
 *
 * This file defines the Factory class, responsible for creating and managing objects.
 *
 * @package Rocket\TestSeoPlugin
 * @since   1.0.0
 */

namespace Rocket\TestSeoPlugin;

require_once ROCKET_TEST_SEO_PLUGIN_ROOT . 'database/class-databasemanager.php';
use Rocket\TestSeoPlugin\Database\DatabaseManager;

/**
 * Factory class.
 *
 * This class provides methods for creating and managing objects.
 *
 * @package Rocket\TestSeoPlugin
 * @since   1.0.0
 */
class Factory {
	/**
	 * Database manager instance.
	 *
	 * @var DatabaseManager $db_manager_instance An instance of the database manager.
	 * @since 1.0.0
	 */
	private static $db_manager_instance;
	/**
	 * Private constructor to prevent direct instantiation.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		// Private constructor to prevent direct instantiation.
	}

	/**
	 * Create a database manager instance.
	 *
	 * If an instance does not exist, it will be created and returned.
	 *
	 * @return DatabaseManager The database manager instance.
	 * @since 1.0.0
	 */
	public static function create_database_manager() {
		if ( ! isset( self::$db_manager_instance ) ) {
			self::$db_manager_instance = new DatabaseManager();
		}
		return self::$db_manager_instance;
	}

	/**
	 * Retrieve the WordPress filesystem object.
	 *
	 * This function initializes and returns the global $wp_filesystem object,
	 * which provides a standardized way to perform file system operations in WordPress.
	 *
	 * @return WP_Filesystem_Base|null The WordPress filesystem object, or null if initialization failed.
	 * @since 1.0.0
	 */
	public static function get_filesystem() {
		global $wp_filesystem;

		if ( ! is_object( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return $wp_filesystem;
	}
}
