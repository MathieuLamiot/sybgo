<?php
/**
 * AI Module class file.
 *
 * Owns all WordPress integration wiring for the AI summarisation domain:
 * creates the AI_Summarizer via the factory and registers the
 * sybgo/generate-summary Ability.
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
 * Responsible for AI summary generation and the sybgo/generate-summary
 * WordPress 7 Ability registration.
 *
 * @since 1.0.0
 */
class AI_Module implements Module_Interface {

	/**
	 * Factory instance.
	 *
	 * @var Factory
	 * @phpstan-ignore property.onlyWritten (used once boot() is implemented in sub-issue #98)
	 */
	private Factory $factory;

	/**
	 * Ability Manager instance.
	 *
	 * @var Ability_Manager
	 * @phpstan-ignore property.onlyWritten (used once boot() is implemented in sub-issue #98)
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
	 * Register AI ability and hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		// No-op stub — implementation follows in a dedicated sub-issue.
	}
}
