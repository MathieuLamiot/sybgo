<?php
/**
 * Sybgo Uninstall
 *
 * Fired when the plugin is deleted (not just deactivated).
 * Removes all data created by the plugin: database tables, cron events, and options.
 *
 * @package Sybgo
 * @since   1.0.0
 */

// Exit if not called by WordPress during uninstall.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

( new Sybgo\Admin\Uninstaller() )->run();
