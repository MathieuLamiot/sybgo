# Explanation - SEO Crawl App/Plugin

## Problem to Be Solved

The goal of this project is to create a PHP-based application or WordPress plugin that assists administrators in improving their website's SEO rankings. The solution should allow administrators to initiate a crawl of their website's internal hyperlinks, display the results, and generate a sitemap for a manual analysis.

## Technical Specification

I chose to develop a WordPress Plugin due to its widespread usage in the global web community.

The plugin delivers the following features:

### Admin Page
A back-end admin page enables administrators to initiate crawls and review results.

### Crawling Logic
The plugin crawls the website's home page, extracting internal hyperlinks and storing the outcomes.

### Result Display
Admins can view crawl results directly on the admin page.

### Sitemap Generation
A dynamic sitemap.html file showcases internal hyperlinks in a structured list format.

### Scheduling Crawls
Crawls are executed immediately upon request and repeated every hour through the WordPress Cron mechanism.

### Error Handling
Displaying error notices in case of issues during crawling.

## Technical Decisions and Rationale

### WordPress Plugin
Opting for a WordPress plugin ensures seamless integration within the WordPress ecosystem, offering administrators a familiar interface.

### Goutte\Client for Parsing
Leveraging Goutte\Client for parsing and extracting internal hyperlinks from the home page's content streamlines HTTP requests, content retrieval, and HTML parsing.

### WordPress Cron
Harnessing the power of WordPress Cron to schedule crawls guarantees consistent updates without reliance on external tools.

### Storage
Employing a MySQL database for storing crawl results provides a scalable and dependable solution.

## Code Explanation

The codebase adheres to the following structure:

### class-testseoplugin.php
This file serves as the main entry point of the plugin.

### class-factory.php
The central class provides quick access to frequently used objects across the project.

### crawler/class-crawler.php
This class implements the crawling logic. It encapsulates code for extracting internal hyperlinks, saving crawled links to the sitemap file, and preserving the homepage as an HTML file.

### database/class-databasemanager.php
This class manages database interactions related to crawling results.

### admin/class-adminpage.php
The AdminPage class enables on-demand crawls and displays the crawled links.

## Achieving Desired Outcome

The plugin satisfies the user story by furnishing administrators with an accessible interface to trigger crawls, review outcomes, and enhance SEO rankings through manual analysis. The crawl logic extracts internal hyperlinks from the homepage, stores the results, and presents them on the admin page. Additionally, the generated sitemap.html file facilitates SEO optimization by offering a concise list of internal hyperlinks.

## Bonus Points

### phpcs Inspection
The codebase adheres to coding standards and successfully passes phpcs inspection.
