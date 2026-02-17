<?php
/**
 * PHPUnit Bootstrap File for Unit Tests
 *
 * This bootstrap does NOT load WordPress, so Brain\Monkey / Patchwork
 * can intercept functions like get_option() without conflicts.
 *
 * @package Rocket\Sybgo\Tests
 */

// Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// WordPress constants for unit tests (WP is not loaded).
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
