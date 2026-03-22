<?php
/**
 * PHPStan stubs for WordPress 7 AI API.
 *
 * These functions are not yet available in szepeviktor/phpstan-wordpress stubs.
 * This file is loaded via bootstrapFiles in phpstan.neon.
 *
 * @package Sybgo
 */

if ( ! class_exists( 'Prompt_Builder_With_WP_Error' ) ) {
	/**
	 * WordPress 7 AI prompt builder stub for static analysis.
	 */
	class Prompt_Builder_With_WP_Error {
		/**
		 * Generate text from the prompt.
		 *
		 * @return string|\WP_Error
		 */
		public function generate_text() {
			return '';
		}
	}
}

if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
	/**
	 * WordPress 7 AI client prompt function stub for static analysis.
	 *
	 * @param string $prompt The prompt text.
	 * @return Prompt_Builder_With_WP_Error
	 */
	function wp_ai_client_prompt( string $prompt ): Prompt_Builder_With_WP_Error {
		return new Prompt_Builder_With_WP_Error();
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	/**
	 * WordPress 7 Ability API registration stub for static analysis.
	 *
	 * @param string              $name Ability name (e.g. 'plugin/action').
	 * @param array<string,mixed> $args Ability arguments.
	 * @return void
	 */
	function wp_register_ability( string $name, array $args ): void {}
}
