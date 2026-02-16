<?php
/**
 * Update Tracker class file.
 *
 * This file defines the Update Tracker for tracking WordPress, plugin, and theme updates.
 *
 * @package Rocket\Sybgo\Events\Trackers
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Events\Trackers;

use Rocket\Sybgo\Database\Event_Repository;
use Rocket\Sybgo\Events\Event_Registry;

/**
 * Update Tracker class.
 *
 * Tracks WordPress core, plugin, and theme updates.
 *
 * @package Rocket\Sybgo\Events\Trackers
 * @since   1.0.0
 */
class Update_Tracker {
	/**
	 * Event repository instance.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $event_repo;

	/**
	 * Constructor.
	 *
	 * @param Event_Repository $event_repo Event repository instance.
	 */
	public function __construct( Event_Repository $event_repo ) {
		$this->event_repo = $event_repo;

		// Register event types with descriptions.
		$this->register_event_types();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Track WordPress core updates.
		add_action( '_core_updated_successfully', array( $this, 'track_core_update' ), 10, 1 );

		// Track plugin and theme updates, installs.
		add_action( 'upgrader_process_complete', array( $this, 'track_upgrader_process' ), 10, 2 );

		// Track plugin activation and deactivation.
		add_action( 'activated_plugin', array( $this, 'track_plugin_activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'track_plugin_deactivated' ), 10, 2 );

		// Track theme switching.
		add_action( 'switch_theme', array( $this, 'track_theme_switched' ), 10, 3 );
	}

	/**
	 * Register event types with AI-friendly descriptions.
	 *
	 * @return void
	 */
	private function register_event_types(): void {
		// WordPress Core Updated event.
		Event_Registry::register_event_type(
			'core_updated',
			function ( array $event_data ): string {
				$description  = "Event Type: WordPress Core Updated\n";
				$description .= "Description: WordPress core was updated to a new version.\n\n";
				$description .= "Data Structure:\n";
				$description .= "  - object.name: 'WordPress Core'\n";
				$description .= "  - metadata.old_version: Previous WordPress version\n";
				$description .= "  - metadata.new_version: New WordPress version\n";
				$description .= "  - metadata.update_type: Type of update (major, minor, security)\n";

				return $description;
			}
		);

		// Plugin Updated event.
		Event_Registry::register_event_type(
			'plugin_updated',
			function ( array $event_data ): string {
				$description  = "Event Type: Plugin Updated\n";
				$description .= "Description: A WordPress plugin was updated to a new version.\n\n";
				$description .= "Data Structure:\n";
				$description .= "  - object.name: Plugin name\n";
				$description .= "  - object.slug: Plugin slug/folder name\n";
				$description .= "  - metadata.old_version: Previous plugin version\n";
				$description .= "  - metadata.new_version: New plugin version\n";

				return $description;
			}
		);

		// Plugin Installed event.
		Event_Registry::register_event_type(
			'plugin_installed',
			function ( array $event_data ): string {
				$description  = "Event Type: Plugin Installed\n";
				$description .= "Description: A new WordPress plugin was installed.\n\n";
				$description .= "Data Structure:\n";
				$description .= "  - object.name: Plugin name\n";
				$description .= "  - object.slug: Plugin slug/folder name\n";
				$description .= "  - metadata.version: Plugin version\n";

				return $description;
			}
		);

		// Plugin Activated event.
		Event_Registry::register_event_type(
			'plugin_activated',
			function ( array $event_data ): string {
				$description  = "Event Type: Plugin Activated\n";
				$description .= "Description: A WordPress plugin was activated.\n\n";
				$description .= "Data Structure:\n";
				$description .= "  - object.name: Plugin name\n";
				$description .= "  - object.slug: Plugin slug/folder name\n";
				$description .= "  - metadata.version: Plugin version\n";

				return $description;
			}
		);

		// Plugin Deactivated event.
		Event_Registry::register_event_type(
			'plugin_deactivated',
			function ( array $event_data ): string {
				$description  = "Event Type: Plugin Deactivated\n";
				$description .= "Description: A WordPress plugin was deactivated.\n\n";
				$description .= "Data Structure:\n";
				$description .= "  - object.name: Plugin name\n";
				$description .= "  - object.slug: Plugin slug/folder name\n";

				return $description;
			}
		);

		// Theme Installed event.
		Event_Registry::register_event_type(
			'theme_installed',
			function ( array $event_data ): string {
				$description  = "Event Type: Theme Installed\n";
				$description .= "Description: A new WordPress theme was installed.\n\n";
				$description .= "Data Structure:\n";
				$description .= "  - object.name: Theme name\n";
				$description .= "  - object.slug: Theme slug/folder name\n";
				$description .= "  - metadata.version: Theme version\n";

				return $description;
			}
		);

		// Theme Updated event.
		Event_Registry::register_event_type(
			'theme_updated',
			function ( array $event_data ): string {
				$description  = "Event Type: Theme Updated\n";
				$description .= "Description: A WordPress theme was updated to a new version.\n\n";
				$description .= "Data Structure:\n";
				$description .= "  - object.name: Theme name\n";
				$description .= "  - object.slug: Theme slug/folder name\n";
				$description .= "  - metadata.old_version: Previous theme version\n";
				$description .= "  - metadata.new_version: New theme version\n";

				return $description;
			}
		);

		// Theme Switched event.
		Event_Registry::register_event_type(
			'theme_switched',
			function ( array $event_data ): string {
				$description  = "Event Type: Theme Switched\n";
				$description .= "Description: The active theme was changed to a different theme.\n\n";
				$description .= "Data Structure:\n";
				$description .= "  - object.name: New theme name\n";
				$description .= "  - object.slug: New theme slug\n";
				$description .= "  - metadata.old_theme: Previous theme name\n";

				return $description;
			}
		);
	}

	/**
	 * Track WordPress core update.
	 *
	 * @param string $new_version The new WordPress version.
	 * @return void
	 */
	public function track_core_update( string $new_version ): void {
		global $wp_version;

		// Determine old version (current before update completes).
		// Note: This hook fires after update, so $wp_version is already new.
		// We'll need to get old version from transient or estimate.
		$old_version = get_transient( 'sybgo_wp_version_before_update' );
		if ( ! $old_version ) {
			$old_version = 'unknown';
		}

		// Determine update type.
		$update_type = $this->determine_update_type( $old_version, $new_version );

		// Build event data.
		$event_data = array(
			'action'   => 'updated',
			'object'   => array(
				'type' => 'core',
				'name' => 'WordPress Core',
				'slug' => 'wordpress',
			),
			'context'  => array(
				'updated_by_id' => get_current_user_id(),
			),
			'metadata' => array(
				'old_version' => $old_version,
				'new_version' => $new_version,
				'update_type' => $update_type,
			),
		);

		// Create event.
		$this->event_repo->create(
			array(
				'event_type'   => 'core_updated',
				'event_subtype' => 'core',
				'object_id'    => 0,
				'user_id'      => get_current_user_id(),
				'event_data'   => $event_data,
			)
		);

		// Store current version for next update.
		set_transient( 'sybgo_wp_version_before_update', $new_version, DAY_IN_SECONDS );
	}

	/**
	 * Track plugin and theme updates via upgrader process.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance.
	 * @param array        $options Update options.
	 * @return void
	 */
	public function track_upgrader_process( \WP_Upgrader $upgrader, array $options ): void {
		// Track both updates and installs.
		if ( ! in_array( $options['action'], array( 'update', 'install' ), true ) ) {
			return;
		}

		// Handle plugin updates and installs.
		if ( 'plugin' === $options['type'] ) {
			if ( 'update' === $options['action'] ) {
				$this->track_plugin_updates( $options );
			} elseif ( 'install' === $options['action'] ) {
				$this->track_plugin_installed( $upgrader, $options );
			}
		}

		// Handle theme updates and installs.
		if ( 'theme' === $options['type'] ) {
			if ( 'update' === $options['action'] ) {
				$this->track_theme_updates( $options );
			} elseif ( 'install' === $options['action'] ) {
				$this->track_theme_installed( $upgrader, $options );
			}
		}
	}

	/**
	 * Track plugin updates.
	 *
	 * @param array $options Update options.
	 * @return void
	 */
	private function track_plugin_updates( array $options ): void {
		if ( ! isset( $options['plugins'] ) || ! is_array( $options['plugins'] ) ) {
			return;
		}

		foreach ( $options['plugins'] as $plugin_file ) {
			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );

			if ( empty( $plugin_data['Name'] ) ) {
				continue;
			}

			// Get plugin slug.
			$slug = dirname( $plugin_file );
			if ( '.' === $slug ) {
				$slug = basename( $plugin_file, '.php' );
			}

			// Build event data.
			$event_data = array(
				'action'   => 'updated',
				'object'   => array(
					'type' => 'plugin',
					'name' => $plugin_data['Name'],
					'slug' => $slug,
				),
				'context'  => array(
					'updated_by_id' => get_current_user_id(),
				),
				'metadata' => array(
					'new_version' => $plugin_data['Version'],
					'old_version' => get_transient( 'sybgo_plugin_version_' . $slug ) ?: 'unknown',
				),
			);

			// Create event.
			$this->event_repo->create(
				array(
					'event_type'   => 'plugin_updated',
					'event_subtype' => 'plugin',
					'object_id'    => 0,
					'user_id'      => get_current_user_id(),
					'event_data'   => $event_data,
				)
			);

			// Store current version.
			set_transient( 'sybgo_plugin_version_' . $slug, $plugin_data['Version'], MONTH_IN_SECONDS );
		}
	}

	/**
	 * Track theme updates.
	 *
	 * @param array $options Update options.
	 * @return void
	 */
	private function track_theme_updates( array $options ): void {
		if ( ! isset( $options['themes'] ) || ! is_array( $options['themes'] ) ) {
			return;
		}

		foreach ( $options['themes'] as $theme_slug ) {
			$theme = wp_get_theme( $theme_slug );

			if ( ! $theme->exists() ) {
				continue;
			}

			// Build event data.
			$event_data = array(
				'action'   => 'updated',
				'object'   => array(
					'type' => 'theme',
					'name' => $theme->get( 'Name' ),
					'slug' => $theme_slug,
				),
				'context'  => array(
					'updated_by_id' => get_current_user_id(),
				),
				'metadata' => array(
					'new_version' => $theme->get( 'Version' ),
					'old_version' => get_transient( 'sybgo_theme_version_' . $theme_slug ) ?: 'unknown',
				),
			);

			// Create event.
			$this->event_repo->create(
				array(
					'event_type'   => 'theme_updated',
					'event_subtype' => 'theme',
					'object_id'    => 0,
					'user_id'      => get_current_user_id(),
					'event_data'   => $event_data,
				)
			);

			// Store current version.
			set_transient( 'sybgo_theme_version_' . $theme_slug, $theme->get( 'Version' ), MONTH_IN_SECONDS );
		}
	}

	/**
	 * Determine update type (major, minor, security).
	 *
	 * @param string $old_version Old version.
	 * @param string $new_version New version.
	 * @return string Update type.
	 */
	private function determine_update_type( string $old_version, string $new_version ): string {
		// Parse versions.
		$old_parts = explode( '.', $old_version );
		$new_parts = explode( '.', $new_version );

		if ( count( $old_parts ) < 2 || count( $new_parts ) < 2 ) {
			return 'unknown';
		}

		// Major version change (e.g., 5.x to 6.x).
		if ( $old_parts[0] !== $new_parts[0] ) {
			return 'major';
		}

		// Minor version change (e.g., 6.1 to 6.2).
		if ( $old_parts[1] !== $new_parts[1] ) {
			return 'minor';
		}

		// Patch/security release (e.g., 6.1.1 to 6.1.2).
		return 'security';
	}

	/**
	 * Track plugin installation.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance.
	 * @param array        $options Install options.
	 * @return void
	 */
	private function track_plugin_installed( \WP_Upgrader $upgrader, array $options ): void {
		// Get the installed plugin file.
		$plugin_file = isset( $upgrader->result['destination_name'] ) ? $upgrader->result['destination_name'] : null;

		if ( ! $plugin_file ) {
			return;
		}

		// Find the main plugin file.
		$plugin_files = get_plugins( '/' . $plugin_file );
		if ( empty( $plugin_files ) ) {
			return;
		}

		// Get the first (main) plugin file.
		$main_file   = key( $plugin_files );
		$plugin_data = $plugin_files[ $main_file ];

		// Build event data.
		$event_data = array(
			'action'   => 'installed',
			'object'   => array(
				'type' => 'plugin',
				'name' => $plugin_data['Name'],
				'slug' => $plugin_file,
			),
			'context'  => array(
				'installed_by_id' => get_current_user_id(),
			),
			'metadata' => array(
				'version' => $plugin_data['Version'],
			),
		);

		// Create event.
		$this->event_repo->create(
			array(
				'event_type'   => 'plugin_installed',
				'event_subtype' => 'plugin',
				'object_id'    => 0,
				'user_id'      => get_current_user_id(),
				'event_data'   => $event_data,
			)
		);
	}

	/**
	 * Track plugin activation.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @param bool   $network_wide Whether activated network-wide.
	 * @return void
	 */
	public function track_plugin_activated( string $plugin_file, bool $network_wide ): void {
		$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );

		if ( empty( $plugin_data['Name'] ) ) {
			return;
		}

		// Get plugin slug.
		$slug = dirname( $plugin_file );
		if ( '.' === $slug ) {
			$slug = basename( $plugin_file, '.php' );
		}

		// Build event data.
		$event_data = array(
			'action'   => 'activated',
			'object'   => array(
				'type' => 'plugin',
				'name' => $plugin_data['Name'],
				'slug' => $slug,
			),
			'context'  => array(
				'activated_by_id' => get_current_user_id(),
			),
			'metadata' => array(
				'version'      => $plugin_data['Version'],
				'network_wide' => $network_wide,
			),
		);

		// Create event.
		$this->event_repo->create(
			array(
				'event_type'   => 'plugin_activated',
				'event_subtype' => 'plugin',
				'object_id'    => 0,
				'user_id'      => get_current_user_id(),
				'event_data'   => $event_data,
			)
		);
	}

	/**
	 * Track plugin deactivation.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @param bool   $network_wide Whether deactivated network-wide.
	 * @return void
	 */
	public function track_plugin_deactivated( string $plugin_file, bool $network_wide ): void {
		$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );

		if ( empty( $plugin_data['Name'] ) ) {
			return;
		}

		// Get plugin slug.
		$slug = dirname( $plugin_file );
		if ( '.' === $slug ) {
			$slug = basename( $plugin_file, '.php' );
		}

		// Build event data.
		$event_data = array(
			'action'   => 'deactivated',
			'object'   => array(
				'type' => 'plugin',
				'name' => $plugin_data['Name'],
				'slug' => $slug,
			),
			'context'  => array(
				'deactivated_by_id' => get_current_user_id(),
			),
			'metadata' => array(
				'network_wide' => $network_wide,
			),
		);

		// Create event.
		$this->event_repo->create(
			array(
				'event_type'   => 'plugin_deactivated',
				'event_subtype' => 'plugin',
				'object_id'    => 0,
				'user_id'      => get_current_user_id(),
				'event_data'   => $event_data,
			)
		);
	}

	/**
	 * Track theme installation.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance.
	 * @param array        $options Install options.
	 * @return void
	 */
	private function track_theme_installed( \WP_Upgrader $upgrader, array $options ): void {
		// Get the installed theme slug.
		$theme_slug = isset( $upgrader->result['destination_name'] ) ? $upgrader->result['destination_name'] : null;

		if ( ! $theme_slug ) {
			return;
		}

		$theme = wp_get_theme( $theme_slug );

		if ( ! $theme->exists() ) {
			return;
		}

		// Build event data.
		$event_data = array(
			'action'   => 'installed',
			'object'   => array(
				'type' => 'theme',
				'name' => $theme->get( 'Name' ),
				'slug' => $theme_slug,
			),
			'context'  => array(
				'installed_by_id' => get_current_user_id(),
			),
			'metadata' => array(
				'version' => $theme->get( 'Version' ),
			),
		);

		// Create event.
		$this->event_repo->create(
			array(
				'event_type'   => 'theme_installed',
				'event_subtype' => 'theme',
				'object_id'    => 0,
				'user_id'      => get_current_user_id(),
				'event_data'   => $event_data,
			)
		);
	}

	/**
	 * Track theme switching.
	 *
	 * @param string    $new_name  New theme name.
	 * @param \WP_Theme $new_theme New theme object.
	 * @param \WP_Theme $old_theme Old theme object.
	 * @return void
	 */
	public function track_theme_switched( string $new_name, \WP_Theme $new_theme, \WP_Theme $old_theme ): void {
		// Build event data.
		$event_data = array(
			'action'   => 'switched',
			'object'   => array(
				'type' => 'theme',
				'name' => $new_theme->get( 'Name' ),
				'slug' => $new_theme->get_stylesheet(),
			),
			'context'  => array(
				'switched_by_id' => get_current_user_id(),
			),
			'metadata' => array(
				'old_theme' => $old_theme->get( 'Name' ),
				'old_slug'  => $old_theme->get_stylesheet(),
				'version'   => $new_theme->get( 'Version' ),
			),
		);

		// Create event.
		$this->event_repo->create(
			array(
				'event_type'   => 'theme_switched',
				'event_subtype' => 'theme',
				'object_id'    => 0,
				'user_id'      => get_current_user_id(),
				'event_data'   => $event_data,
			)
		);
	}
}
