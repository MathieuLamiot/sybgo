<?php
/**
 * PHPUnit Bootstrap File
 *
 * @package Rocket\Sybgo\Tests
 */

// Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// WordPress test suite location.
if ( ! defined( 'WP_TESTS_DIR' ) ) {
	$wp_tests_dir = getenv( 'WP_TESTS_DIR' );
	if ( ! $wp_tests_dir ) {
		$wp_tests_dir = '/tmp/wordpress-tests-lib';
	}
	define( 'WP_TESTS_DIR', $wp_tests_dir );
}

// Plugin constants.
if ( ! defined( 'SYBGO_PLUGIN_DIR' ) ) {
	define( 'SYBGO_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'SYBGO_PLUGIN_FILE' ) ) {
	define( 'SYBGO_PLUGIN_FILE', dirname( __DIR__ ) . '/class-sybgo.php' );
}

// WordPress constants for unit tests.
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

// Load WordPress test library if available (for integration tests).
if ( file_exists( WP_TESTS_DIR . '/includes/functions.php' ) ) {
	require_once WP_TESTS_DIR . '/includes/functions.php';

	/**
	 * Manually load plugin for testing.
	 */
	function _manually_load_plugin() {
		require SYBGO_PLUGIN_FILE;
	}
	tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

	// Start WordPress test suite.
	require WP_TESTS_DIR . '/includes/bootstrap.php';
}
