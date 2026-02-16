<?php
/**
 * DatabaseManager class file.
 *
 * This file defines the DatabaseManager class, responsible for managing database interactions.
 *
 * @package Rocket\TestSeoPlugin\Database
 * @since   1.0.0
 */

namespace Rocket\TestSeoPlugin\Database;

/**
 * DatabaseManager class.
 *
 * This class provides methods for managing database interactions related to crawling results.
 *
 * @package Rocket\TestSeoPlugin\Database
 * @since   1.0.0
 */
class DatabaseManager {
	/**
	 * Table name for storing crawling results.
	 *
	 * @var string $crawl_table The name of the database table.
	 * @since 1.0.0
	 */
	private $crawl_table = '';

	/**
	 * Constructor for the DatabaseManager class.
	 *
	 * This method initializes the database manager and sets up the crawling results table.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;

		// Table name.
		$this->crawl_table = $wpdb->prefix . 'crawling_results';

		// SQL query to create the table if it doesn't exist.
		$create_table_sql = "
			CREATE TABLE IF NOT EXISTS $this->crawl_table (
				id INT AUTO_INCREMENT PRIMARY KEY,
				link VARCHAR(255) NOT NULL
			)
		";

		// Create or update the table.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $create_table_sql );
	}

	/**
	 * Get stored crawling links from the database.
	 *
	 * @return array An array of stored links.
	 * @since 1.0.0
	 */
	public function get_stored_links() {
		$cached_links = wp_cache_get( 'stored_links', 'test_seo_plugin_cache_group' );

		if ( false !== $cached_links ) {
			echo 'OK. The links are found in cache<br>';
			return $cached_links;
		}

		global $wpdb;

		$links = $wpdb->get_col( "SELECT `link` FROM {$wpdb->prefix}crawling_results" ); // @codingStandardsIgnoreLine

		wp_cache_set( 'stored_links', $links, 'test_seo_plugin_cache_group' );

		return $links;
	}

	/**
	 * Delete all stored crawling results from the database.
	 *
	 * @since 1.0.0
	 */
	public function delete_crawling_results() {
		global $wpdb;

		wp_cache_delete( 'stored_links', 'test_seo_plugin_cache_group' );

		// Use suppress_errors to avoid printing errors directly.
		$wpdb->suppress_errors();
		$result = $wpdb->query( "DELETE FROM {$wpdb->prefix}crawling_results" ); // @codingStandardsIgnoreLine
		$wpdb->suppress_errors( false );

		if ( false !== $result ) {
			echo 'Crawling results deleted successfully.<br>';
		} else {
			$wpdb->print_error();
		}
	}


	/**
	 * Store crawling results in the database.
	 *
	 * @param array $links An array of links to store.
	 * @since 1.0.0
	 */
	public function store_crawling_results( $links ) {
		global $wpdb;

		foreach ( $links as $link ) {
			$result = $wpdb->insert( $this->crawl_table, array( 'link' => $link ) ); // @codingStandardsIgnoreLine
			if ( false === $result ) {
				$wpdb->print_error();
				break; // Stop if an error occurs.
			}
		}
		echo 'Crawling results stored successfully.<br>';
	}
}
