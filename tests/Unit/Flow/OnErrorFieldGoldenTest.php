<?php

/**
 * The page-level nodes describe their on-error policy field the same way.
 *
 * Pins the config forms of the apply-mapping, contract, contract-commit and
 * contract-sweep nodes: their fields in order, and the exact on-error field
 * each carries, so the shared field definition can move without changing
 * what the flow editor shows.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Flow;

use OCA\Integriq\Flow\ApplyMappingNode;
use OCA\Integriq\Flow\ContractCommitNode;
use OCA\Integriq\Flow\ContractMatchNode;
use OCA\Integriq\Flow\ContractSweepNode;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden tests for the on-error field of the page-level nodes.
 */
final class OnErrorFieldGoldenTest extends TestCase {

	/**
	 * Each node and its fields in order.
	 *
	 * @return array<string, array{0: class-string, 1: array<int, string>}>
	 */
	public static function nodes(): array {
		return [
			'apply mapping' => [ApplyMappingNode::class, ['mapping', 'input', 'output', 'onError', 'ownership', 'exists']],
			'contract commit' => [ContractCommitNode::class, ['synchronization', 'contractPosition', 'targetIdPosition', 'targetHashPosition', 'onError']],
			'contract match' => [ContractMatchNode::class, ['synchronization', 'idPosition', 'hashPosition', 'output', 'onError']],
			'contract sweep' => [ContractSweepNode::class, ['synchronization', 'targetIdsPosition', 'fetchComplete', 'force', 'onError']],
		];

	}//end nodes()

	/**
	 * The form keeps its fields and its on-error field.
	 *
	 * @param class-string       $class The node.
	 * @param array<int, string> $keys  Its fields in order.
	 *
	 * @return void
	 */
	#[DataProvider('nodes')]
	public function testTheFormKeepsItsOnErrorField(string $class, array $keys): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => '[' . $text . ']');
		$reflection = new ReflectionClass($class);
		$node = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('l10n')->setValue($node, $l10n);

		$form = $node->configForm();

		$this->assertSame($keys, array_column($form, 'key'));
		$this->assertSame(
			[
				'key' => 'onError',
				'label' => '[On error]',
				'type' => 'text',
				'help' => '[What a failed item does to the run: stop, continue or dead_letter.]',
			],
			$form[array_search('onError', $keys, true)]
		);

	}//end testTheFormKeepsItsOnErrorField()
}//end class
