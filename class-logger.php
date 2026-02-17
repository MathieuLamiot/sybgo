<?php
/**
 * Logger class file.
 *
 * Provides centralized logging for the Sybgo plugin.
 *
 * @package Rocket\Sybgo
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Rocket\Sybgo;

/**
 * Logger class.
 *
 * Wraps error_log() to centralize logging and respect WP_DEBUG settings.
 *
 * @package Rocket\Sybgo
 * @since   1.0.0
 */
class Logger {
	/**
	 * Log an informational message (only when WP_DEBUG is enabled).
	 *
	 * @param string $message The message to log.
	 * @return void
	 */
	public static function info( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional structured logging.
			error_log( 'Sybgo: ' . $message );
		}
	}

	/**
	 * Log an error message (always logged).
	 *
	 * @param string $message The message to log.
	 * @return void
	 */
	public static function error( string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional structured logging.
		error_log( 'Sybgo Error: ' . $message );
	}
}
