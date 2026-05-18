<?php
/**
 * Admin Manager class file.
 *
 * Pure WordPress admin registration utility. Has zero knowledge of which admin
 * pages are registered or what handlers are wired. Callers declare pages and
 * hook callbacks via the register methods; this class handles the WP wiring.
 *
 * @package Sybgo\Admin
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Manager.
 *
 * Accepts admin page instances via register_page(), and optional hook
 * callbacks via register_cleanup_handler() and register_asset_enqueuer().
 * Calling init() initialises each page and wires the registered hooks.
 *
 * @package Sybgo\Admin
 * @since   1.0.0
 */
class Admin_Manager {

	/**
	 * Registered admin page instances.
	 *
	 * Each entry must expose an init() method.
	 *
	 * @var object[]
	 */
	private array $pages = array();

	/**
	 * Callback for the admin_post_sybgo_run_cleanup action.
	 *
	 * @var callable|null
	 */
	private $cleanup_handler = null;

	/**
	 * Callback for the admin_enqueue_scripts action.
	 *
	 * @var callable|null
	 */
	private $asset_enqueuer = null;

	/**
	 * Register an admin page.
	 *
	 * Must be called before init(). Each page's init() method is called
	 * during Admin_Manager::init().
	 *
	 * @param object $page Admin page instance (Dashboard_Widget, Settings_Page, Reports_Page, …).
	 * @return void
	 */
	public function register_page( object $page ): void {
		$this->pages[] = $page;
	}

	/**
	 * Register the manual cleanup form handler.
	 *
	 * The callback is wired to admin_post_sybgo_run_cleanup during init().
	 *
	 * @param callable $handler Callback for the cleanup form submission.
	 * @return void
	 */
	public function register_cleanup_handler( callable $handler ): void {
		$this->cleanup_handler = $handler;
	}

	/**
	 * Register the admin asset enqueuer.
	 *
	 * The callback is wired to admin_enqueue_scripts (priority 5) during init().
	 *
	 * @param callable $enqueuer Callback receiving the current admin page hook string.
	 * @return void
	 */
	public function register_asset_enqueuer( callable $enqueuer ): void {
		$this->asset_enqueuer = $enqueuer;
	}

	/**
	 * Initialise all registered admin pages and wire hook callbacks.
	 *
	 * @return void
	 */
	public function init(): void {
		foreach ( $this->pages as $page ) {
			$page->init();
		}

		if ( null !== $this->cleanup_handler ) {
			add_action( 'admin_post_sybgo_run_cleanup', $this->cleanup_handler );
		}

		if ( null !== $this->asset_enqueuer ) {
			add_action( 'admin_enqueue_scripts', $this->asset_enqueuer, 5 );
		}
	}
}
