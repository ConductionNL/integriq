<?php

/**
 * Golden master of the palette metadata of every flow node and workflow operation.
 *
 * Records, per class, what the editor palette reads: id or entity, display
 * name, description, icon, the scopes the class is offered in, the config
 * keys and the config form. The translator echoes its text and parameters,
 * so a changed string shows in the diff. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Flow;

use OCA\Integriq\Flow\ApplyMappingNode;
use OCA\Integriq\Flow\ApprovalRequestNode;
use OCA\Integriq\Flow\ContractCommitNode;
use OCA\Integriq\Flow\ContractMatchNode;
use OCA\Integriq\Flow\ContractSweepNode;
use OCA\Integriq\Flow\EventEmitNode;
use OCA\Integriq\Flow\FetchFileNode;
use OCA\Integriq\Flow\SourceCallNode;
use OCA\Integriq\Flow\SourcePaginateNode;
use OCA\Integriq\Flow\SynchronizationRunNode;
use OCA\Integriq\WorkflowEngine\CallEndpointOperation;
use OCA\Integriq\WorkflowEngine\FireCloudEventOperation;
use OCA\Integriq\WorkflowEngine\RunSynchronizationOperation;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Recorded palette metadata per node and operation class.
 *
 * @covers \OCA\Integriq\Flow\ApplyMappingNode
 * @covers \OCA\Integriq\Flow\ApprovalRequestNode
 * @covers \OCA\Integriq\Flow\ContractCommitNode
 * @covers \OCA\Integriq\Flow\ContractMatchNode
 * @covers \OCA\Integriq\Flow\ContractSweepNode
 * @covers \OCA\Integriq\Flow\EventEmitNode
 * @covers \OCA\Integriq\Flow\FetchFileNode
 * @covers \OCA\Integriq\Flow\SourceCallNode
 * @covers \OCA\Integriq\Flow\SourcePaginateNode
 * @covers \OCA\Integriq\Flow\SynchronizationRunNode
 * @covers \OCA\Integriq\WorkflowEngine\CallEndpointOperation
 * @covers \OCA\Integriq\WorkflowEngine\FireCloudEventOperation
 * @covers \OCA\Integriq\WorkflowEngine\RunSynchronizationOperation
 */
class PaletteMetadataGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/palette-metadata.json';

	private const CLASSES = [
		ApplyMappingNode::class,
		ApprovalRequestNode::class,
		ContractCommitNode::class,
		ContractMatchNode::class,
		ContractSweepNode::class,
		EventEmitNode::class,
		FetchFileNode::class,
		SourceCallNode::class,
		SourcePaginateNode::class,
		SynchronizationRunNode::class,
		CallEndpointOperation::class,
		FireCloudEventOperation::class,
		RunSynchronizationOperation::class,
	];

	/**
	 * Build the class with a mock for every constructor parameter.
	 *
	 * @param string $class The class name.
	 *
	 * @return object
	 */
	private function build(string $class): object {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $params = []) => 't(' . $text . ($params === [] ? '' : ' ' . json_encode($params)) . ')'
		);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $image) => $app . '/' . $image);

		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $param) {
			$type = $param->getType();
			$name = ($type instanceof ReflectionNamedType) ? $type->getName() : '';
			$args[] = match (true) {
				$name === IL10N::class => $l10n,
				$name === IURLGenerator::class => $urls,
				default => $this->createMock($name),
			};
		}

		return new $class(...$args);
	}//end build()

	/**
	 * The palette metadata of one class.
	 *
	 * @param object $subject The node or operation.
	 *
	 * @return array<string, mixed>
	 */
	private function metadata(object $subject): array {
		$out = [];
		foreach (['getId', 'getEntityId', 'getDisplayName', 'getDescription', 'getIcon', 'configKeys', 'configForm'] as $method) {
			if (method_exists($subject, $method) === true) {
				$out[$method] = $subject->$method();
			}
		}

		foreach ([0, 1, 2, 3] as $scope) {
			$out['scope' . $scope] = $subject->isAvailableForScope($scope);
		}

		return $out;
	}//end metadata()

	/**
	 * Every class against the recorded fixture, class by class.
	 *
	 * @return void
	 */
	public function testPaletteMetadataMatchesTheRecording(): void {
		$found = [];
		foreach (self::CLASSES as $class) {
			$found[$class] = $this->metadata(subject: $this->build(class: $class));
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($found, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(array_keys($golden), array_keys($found));
		foreach ($golden as $class => $expected) {
			$this->assertSame($expected, $found[$class], $class);
		}
	}//end testPaletteMetadataMatchesTheRecording()
}//end class
