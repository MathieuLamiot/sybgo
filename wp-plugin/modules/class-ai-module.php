<?php
/**
 * AI Module class file.
 *
 * Owns all WordPress integration wiring for the AI summarisation domain:
 * registers the sybgo/generate-summary Ability via the WordPress 7 Ability API.
 *
 * @package Sybgo\Modules
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\Modules;

use Sybgo\Ability_Manager;
use Sybgo\Factory;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Module.
 *
 * Responsible for the sybgo/generate-summary WordPress 7 Ability registration.
 * The execute_callback lazily creates the AI_Summarizer via the factory, so
 * the ability is registered on all WP7+ installs regardless of whether AI is
 * configured — the callback returns null when the summarizer is unavailable.
 *
 * @since 1.0.0
 */
class AI_Module implements Module_Interface {

	/**
	 * Factory instance.
	 *
	 * @var Factory
	 */
	private Factory $factory;

	/**
	 * Ability Manager instance.
	 *
	 * @var Ability_Manager
	 */
	private Ability_Manager $abilities;

	/**
	 * Constructor.
	 *
	 * @param Factory         $factory   Factory instance.
	 * @param Ability_Manager $abilities Ability Manager instance.
	 */
	public function __construct( Factory $factory, Ability_Manager $abilities ) {
		$this->factory   = $factory;
		$this->abilities = $abilities;
	}

	/**
	 * Register the sybgo/generate-summary ability.
	 *
	 * Deferred to the 'init' hook at priority 5 so that __() evaluates after
	 * the 'sybgo' text domain is loaded. See Event_Module::boot() for the same
	 * rationale. Ability_Manager::init() runs at priority 20 on the same hook.
	 *
	 * @return void
	 */
	public function boot(): void {
		$abilities = $this->abilities;
		$factory   = $this->factory;

		add_action(
			'init',
			static function () use ( $abilities, $factory ): void {
				$abilities->register(
					'sybgo/generate-summary',
					array(
						'label'               => __( 'Generate Weekly Summary', 'sybgo' ),
						'description'         => __( 'Generates an AI-powered summary of the weekly site activity report.', 'sybgo' ),
						'category'            => 'sybgo',
						'execute_callback'    => static function () use ( $factory ): ?string {
							$ai_summarizer = $factory->create_ai_summarizer();
							if ( null === $ai_summarizer ) {
								return null;
							}
							$last_frozen = $factory->create_report_repository()->get_last_frozen();
							if ( ! $last_frozen ) {
								return null;
							}
							// Summary generation is handled via Report_Generator; stub for Ability API.
							return null;
						},
						'permission_callback' => static function (): bool {
							return current_user_can( 'manage_options' );
						},
					)
				);
			},
			5
		);
	}
}
