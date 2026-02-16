<?php
/**
 * Test plugin to crawl links.
 *
 * @package Rocket\TestSeoPlugin
 *
 * Plugin Name: Test SEO Plugin
 * Description: A plugin to improve SEO by crawling internal links.
 * Version: 1.0.2
 * Author: Muhammad Saeed
 */

namespace Rocket\TestSeoPlugin;

define( 'ROCKET_TEST_SEO_PLUGIN_ROOT', plugin_dir_path( __FILE__ ) );

use Rocket\TestSeoPlugin\Admin\AdminPage;
use Rocket\TestSeoPlugin\Crawl\Crawler;

require_once plugin_dir_path( __FILE__ ) . 'class-factory.php';
use Rocket\TestSeoPlugin\Factory;

/**
 * Class TestSeoPlugin
 *
 * This class defines and initializes the Test SEO Plugin.
 *
 * @package Rocket\TestSeoPlugin
 * @since   1.0.0
 */
class TestSeoPlugin {
	/**
	 * Factory instance for creating objects.
	 *
	 * @var MyFactoryClass $factory An instance of the factory class.
	 * @since 1.0.0
	 */
	public $factory;

	/**
	 * Constructor for the TestSeoPlugin class.
	 *
	 * This method initializes the plugin and sets up necessary hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Activation and Deactivation Hooks.
		register_activation_hook( __FILE__, [ $this, 'activate' ] );
		register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );

		// Hook to add admin menu.
		add_action( 'admin_menu', [ $this, 'add_admin_page' ] );

		// Schedule the crawlWebsite event to run hourly.
		if ( ! wp_next_scheduled( 'crawl_website_event' ) ) {
			wp_schedule_event( time(), 'hour', 'crawl_website_event' );
		}
		add_action( 'crawl_website_event', [ $this, 'crawl_website_callback' ] );
	}

	/**
	 * Callback function to be executed when the event is triggered
	 */
	public function crawl_website_callback() {
		include_once ROCKET_TEST_SEO_PLUGIN_ROOT . 'crawler/crawler.php';
		$crawler = new Crawler();

		$home_page_url = get_home_url();
		$crawl_results = $crawler->crawlWebsite( $home_page_url );
	}

	/**
	 * Activation Hook
	 */
	public function activate() {
		// Perform activation tasks if needed.
	}

	/**
	 * Deactivation Hook
	 */
	public function deactivate() {
		// Perform deactivation tasks if needed.
	}

	/**
	 * Method to add admin page
	 */
	public function add_admin_page() {
		add_menu_page(
			'Test SEO Plugin',
			'Test SEO',
			'manage_options',
			'test-seo-plugin',
			[ $this, 'render_admin_page' ]
		);
	}

	/**
	 * Method to render admin page content
	 */
	public function render_admin_page() {
		// Include the admin page file.
		include_once plugin_dir_path( __FILE__ ) . '/admin/class-adminpage.php';
		$admin_page = new AdminPage();
		$admin_page->show_admin_page();
	}
}

// Initialize the plugin.
$rocket_test_seo_plugin = new TestSeoPlugin();
