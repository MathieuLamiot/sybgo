<?php
/**
 * PHPStan rule to discourage direct usage of apply_filters().
 *
 * @package Sybgo\Tests\phpstan\Rules
 */

namespace Sybgo\Tests\phpstan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Discourages the use of apply_filters() in favor of typed alternatives.
 */
class DiscourageApplyFilters implements Rule {

	/**
	 * Returns the node type this rule applies to.
	 *
	 * @return string
	 */
	public function getNodeType(): string {
		return FuncCall::class;
	}

	/**
	 * Processes a node and returns any rule errors.
	 *
	 * @param Node  $node  The AST node.
	 * @param Scope $scope The current analysis scope.
	 * @return array<\PHPStan\Rules\RuleError>
	 */
	public function processNode( Node $node, Scope $scope ): array {
		if ( ! $node instanceof FuncCall ) {
			return [];
		}

		if ( $node->name instanceof Node\Name && $node->name->toString() === 'apply_filters' ) {
			return [
				RuleErrorBuilder::message( 'Usage of apply_filters() is discouraged. Use wpm_apply_filters_typesafe() or wpm_apply_filters_typed() instead.' )
					->identifier( 'custom.rules.discourageApplyFilters' )
					->addTip( 'See https://github.com/wp-media/apply-filters-typed for usage.' )
					->build(),
			];
		}

		return [];
	}
}
