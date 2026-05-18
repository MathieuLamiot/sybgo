<?php
/**
 * Ability Manager class file.
 *
 * Owns WordPress 7 Ability API registration mechanics.
 * Acts as a pure registration utility — callers declare abilities via
 * register(); this class handles the WP7 API wiring.
 *
 * @package Sybgo
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability Manager.
 *
 * Accepts ability definitions via register() and, when running on
 * WordPress 7+, registers them all on the wp_abilities_api_init action.
 *
 * @package Sybgo
 * @since   1.0.0
 */
class Ability_Manager {

	/**
	 * Ability definitions keyed by ability name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $abilities = array();

	/**
	 * Register an ability definition.
	 *
	 * Must be called before init(). The $args array is passed verbatim to
	 * wp_register_ability() when WordPress 7+ is available.
	 *
	 * @param string               $name Ability name (e.g. 'sybgo/generate-summary').
	 * @param array<string, mixed> $args Ability arguments (label, description, category,
	 *                                   execute_callback, permission_callback).
	 * @return void
	 */
	public function register( string $name, array $args ): void {
		$this->abilities[ $name ] = $args;
	}

	/**
	 * Wire all registered abilities into WordPress.
	 *
	 * Safe to call on any WordPress version — the WP7 block is guarded by
	 * function_exists( 'wp_register_ability' ).
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$abilities = $this->abilities;

		add_action(
			'wp_abilities_api_init',
			static function () use ( $abilities ): void {
				foreach ( $abilities as $name => $args ) {
					wp_register_ability( $name, $args );
				}
			}
		);
	}
}
