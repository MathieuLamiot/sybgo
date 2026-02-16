<?php
/**
 * Crawl class file.
 *
 * This file contains the Crawler class responsible for website crawling and link extraction.
 *
 * @package Rocket\TestSeoPlugin\Crawl
 */

namespace Rocket\TestSeoPlugin\Crawl;

require_once ROCKET_TEST_SEO_PLUGIN_ROOT . 'vendor/autoload.php';

use Goutte\Client;
use Rocket\TestSeoPlugin\Factory;

/**
 * Class Crawler
 *
 * Handles website crawling and link extraction.
 */
class Crawler {
	/**
	 * Crawler constructor.
	 *
	 * Initializes the Crawler class.
	 */
	public function __construct() {
	}

	/**
	 * Crawl a website and perform various tasks.
	 *
	 * @param string $url The URL of the website to crawl.
	 * @return array An array of internal links extracted during crawling.
	 */
	public function crawl_website( $url ) {
		$db_manager = Factory::create_database_manager();
		// Delete previous crawl results.
		$db_manager->delete_crawling_results();

		// Start crawling from the root URL.
		$internal_links = $this->extract_internal_links( $url );

		// Store results in the database.
		$db_manager->store_crawling_results( $internal_links );

		// Save home page as .html.
		if ( ! $this->save_home_page_html( $url ) ) {
			echo 'Error saving homepage html <br>';
		}

		// Create sitemap.html.
		if ( ! $this->create_sitemap_html( $internal_links ) ) {
			echo 'Error saving sitemap html <br>';
		}

		return $internal_links;
	}

	/**
	 * Extract internal links from a given URL.
	 *
	 * @param string $url The URL from which to extract internal links.
	 * @return array An array of internal links.
	 */
	private function extract_internal_links( $url ) {
		$client  = new Client();
		$crawler = $client->request( 'GET', $url );

		$internal_links = [];

		// Extract internal links from anchor tags.
		$crawler->filter( 'a' )->each(
			function ( $node ) use ( &$internal_links ) {
				$link = $node->link()->getUri();

				if ( $this->is_internal_link( $link ) ) {
					$internal_links[] = $link;
				}
			}
		);

		return $internal_links;
	}

	/**
	 * Check if a given link is an internal link.
	 *
	 * @param string $link The link to check.
	 * @return bool True if the link is internal, false otherwise.
	 */
	private function is_internal_link( $link ) {
		$base_url        = site_url();
		$parsed_link     = wp_parse_url( $link );
		$parsed_base_url = wp_parse_url( $base_url );

		if ( $parsed_link['host'] === $parsed_base_url['host'] ) {
			return true;
		}

		return false;
	}

	/**
	 * Save the home page content as an HTML file.
	 *
	 * @param string $url The URL of the home page.
	 * @return bool True if the file was saved successfully, false otherwise.
	 */
	private function save_home_page_html( $url ) {
		$wp_filesystem = Factory::get_filesystem();

		// Fetch the home page content.
		$client  = new Client();
		$crawler = $client->request( 'GET', $url );
		$content = $crawler->html();

		// Save as .html file.
		$file_path = ROCKET_TEST_SEO_PLUGIN_ROOT . 'output/homepage.html';
		if ( $wp_filesystem->put_contents( $file_path, $content, FS_CHMOD_FILE ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Create an HTML sitemap file based on internal links.
	 *
	 * @param array $links An array of internal links for the sitemap.
	 * @return bool True if the file was created successfully, false otherwise.
	 */
	private function create_sitemap_html( $links ) {
		$wp_filesystem = Factory::get_filesystem();

		// Generate sitemap structure.
		$sitemap_content = '<ul>';
		foreach ( $links as $link ) {
			$sitemap_content .= '<li>' . $link . '</li>';
		}
		$sitemap_content .= '</ul>';

		// Save as sitemap.html.
		$file_path = ROCKET_TEST_SEO_PLUGIN_ROOT . 'output/sitemap.html';
		if ( $wp_filesystem->put_contents( $file_path, $sitemap_content, FS_CHMOD_FILE ) ) {
			return true;
		}
	}
}
