<?php
/**
 * MCP Module class file.
 *
 * Bootstraps the MCP connect helper by calling MCP_Helper::init().
 * The helper is self-contained and idempotent; this module is only
 * responsible for triggering it at the right moment in Sybgo's boot sequence.
 *
 * @package Sybgo\Modules
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\Modules;

use WPMedia\MCPHelper\MCP_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MCP Module.
 *
 * Delegates all WordPress hook registration to MCP_Helper::init().
 * The static guard inside MCP_Helper ensures the hooks are registered
 * exactly once even if another plugin using the same lib calls init() too.
 *
 * @since 1.0.0
 */
class MCP_Module implements Module_Interface {

	/**
	 * Boot the MCP connect helper.
	 *
	 * @return void
	 */
	public function boot(): void {
		MCP_Helper::init();
	}
}
