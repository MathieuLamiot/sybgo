<?php
/**
 * Admin Page class file.
 *
 * This file contains the AdminPage class responsible for running on-demand crawl and displaying crawled links.
 *
 * @package Rocket\TestSeoPlugin\Admin
 */

namespace Rocket\TestSeoPlugin\Admin;

use Rocket\TestSeoPlugin\Factory;
use Rocket\TestSeoPlugin\Crawl\Crawler;

/**
 * Class AdminPage
 *
 * Handles admin page functionality and displays crawled links.
 */
class AdminPage {
	/**
	 * Constructor for the AdminPage class.
	 */
	public function __construct() {
	}

	/**
	 * Display the stored links on the admin page.
	 */
	public function show_stored_links() {
		$db_manager   = Factory::create_database_manager();
		$stored_links = $db_manager->get_stored_links();

		if ( empty( $stored_links ) ) {
			echo '<h2>Sorry, no links there.</h2>';
		} else {
			echo '<h2>Crawled Links</h2>';
			echo '<ul>';
			foreach ( $stored_links as $link ) {
				echo '<li>' . esc_html( $link ) . '</li>';
			}
			echo '</ul>';
		}
	}

	/**
	 * Display the admin page content.
	 */
	public function show_admin_page() {
		include_once ROCKET_TEST_SEO_PLUGIN_ROOT . 'crawler/class-crawler.php';
		$crawler = new Crawler();
		?>
		<div class="wrap">
		<h1>SEO Crawl Admin Page</h1>
			<p>Welcome to the SEO Crawl admin page. Click the button below to trigger a crawl.</p>
			<form method="post" action="">
			<?php wp_nonce_field( 'seo_crawl', 'seo_crawl_nonce' ); ?>
				<input type="submit" class="button button-primary" name="crawl_button" value="Trigger Crawl">
				<input type="submit" class="button" name="show_links_button" value="Show Last Crawled Links">
			</form>
			<?php
			if ( isset( $_POST['crawl_button'] ) ) {
				// Verify the nonce.
				if ( ! check_admin_referer( 'seo_crawl', 'seo_crawl_nonce' ) ) {
					wp_die( 'Unauthorized request' );
				}

				// Perform crawl on the home page.
				$home_page_url = get_home_url();
				$crawl_results = $crawler->crawl_website( $home_page_url );

				$this->show_stored_links();

			} elseif ( isset( $_POST['show_links_button'] ) ) {
				$this->show_stored_links();
			}
			?>
		</div>
		<?php
	}
}
