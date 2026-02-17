<?php
/**
 * PHPUnit Bootstrap File for Integration Tests
 *
 * Loads the WordPress test suite for integration testing.
 *
 * @package Rocket\Sybgo\Tests
 */

// Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// WordPress test suite directory.
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	// Load WordPress test suite.
	require_once $_tests_dir . '/includes/functions.php';

	/**
	 * Load the library during tests.
	 */
	tests_add_filter( 'muplugins_loaded', function () {
		require_once dirname( __DIR__ ) . '/class-logger.php';
		require_once dirname( __DIR__ ) . '/class-factory.php';
	} );

	// Start up the WP testing environment.
	require $_tests_dir . '/includes/bootstrap.php';
} else {
	// Fallback: define constants for when WordPress is not available.
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}
	if ( ! defined( 'OBJECT' ) ) {
		define( 'OBJECT', 'OBJECT' );
	}
}
