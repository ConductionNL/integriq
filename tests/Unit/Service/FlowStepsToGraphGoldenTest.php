<?php

/**
 * Golden master of FlowStepsToGraphTranslator.
 *
 * Every case is one flow's steps and records the graph translate() builds,
 * or the refusal it throws with every reason, in order. Set RECORD_GOLDEN=1
 * to rewrite the fixture.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\EntityNotMigratableException;
use OCA\Integriq\Service\FlowStepsToGraphTranslator;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Service\FlowStepsToGraphTranslator
 */
class FlowStepsToGraphGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/../../fixtures/golden/flow-steps-to-graph.json';

	/**
	 * Every case against the recorded fixture.
	 *
	 * @return void
	 */
	public function testTheGraphsMatchTheRecording(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$translator = new FlowStepsToGraphTranslator(l10n: $l10n);

		$branch = ['order' => 2, 'type' => 'branch', 'branches' => [
			['condition' => ['==' => [1, 1]], 'nextStepOrder' => 4],
			['condition' => [], 'nextStepOrder' => 3],
			'junk',
			['condition' => ['var' => 'x']],
			['condition' => ['!' => ['var' => 'y']], 'nextStepOrder' => '3'],
		]];
		$flows = [
			'every-type' => ['name' => 'Alles', 'steps' => [
				['order' => 50, 'type' => 'approval', 'config' => ['approverGroup' => 'admin', 'ttlSeconds' => '60', 'onReject' => 'skip'], 'onError' => 'continue'],
				['order' => 10, 'type' => 'call', 'configRef' => ' src ', 'config' => ['endpoint' => '/x', 'method' => 'POST']],
				['order' => 20, 'type' => 'mapping', 'configRef' => 'm'],
				['order' => 30, 'type' => 'synchronization', 'configRef' => 's', 'config' => ['force' => 1], 'onError' => ' '],
				['order' => 40, 'type' => 'event', 'config' => ['type' => 'nl.x', 'source' => 'integriq', 'subject' => 'abc']],
				['order' => 60, 'type' => 'approval', 'config' => ['approverGroup' => 'g', 'question' => ' Mag dit? ']],
				'not-a-step',
			]],
			'defaults' => ['uuid' => 'u-1', 'steps' => [
				['order' => 1, 'type' => 'call', 'configRef' => 'src'],
				['order' => 2, 'type' => 'synchronization', 'configRef' => 's'],
				['order' => 3, 'type' => 'event', 'config' => ['type' => 'nl.y', 'subject' => ' ']],
			]],
			'branch-middle' => ['steps' => [
				['order' => 1, 'type' => 'mapping', 'configRef' => 'm'],
				$branch,
				['order' => 3, 'type' => 'mapping', 'configRef' => 'm3'],
				['order' => 4, 'type' => 'mapping', 'configRef' => 'm4'],
			]],
			'branch-last-with-default' => ['steps' => [
				['order' => 1, 'type' => 'mapping', 'configRef' => 'm'],
				['order' => 2, 'type' => 'branch', 'branches' => [['condition' => ['var' => 'a'], 'nextStepOrder' => 1]], 'defaultNextStepOrder' => '1'],
			]],
			'branch-first' => ['steps' => [
				['order' => 5, 'type' => 'branch', 'branches' => []],
				['order' => 6, 'type' => 'mapping', 'configRef' => 'm'],
			]],
			'no-steps' => ['name' => 'Leeg', 'steps' => []],
			'no-steps-key' => [],
			'duplicates-and-unsupported' => ['name' => 'Fout', 'steps' => [
				['order' => 1, 'type' => 'call'],
				['order' => 1, 'type' => 'webhook'],
				['type' => 'mapping', 'configRef' => 'm', 'condition' => ['var' => 'x']],
			]],
			'every-config-refusal' => ['steps' => [
				['order' => 1, 'type' => 'call', 'configRef' => 'src', 'config' => ['requestConfig' => ['a' => 1]]],
				['order' => 2, 'type' => 'synchronization', 'config' => ['isTest' => true, 'mutationType' => 'create']],
				['order' => 3, 'type' => 'event', 'config' => ['type' => ' ']],
				['order' => 4, 'type' => 'approval', 'config' => []],
				['order' => 5, 'type' => 'branch', 'branches' => [['condition' => ['var' => 'x'], 'nextStepOrder' => 9]], 'defaultNextStepOrder' => 8],
				['order' => 6, 'type' => 'mapping', 'configRef' => ' '],
				['order' => 7, 'type' => 'synchronization', 'configRef' => 's', 'config' => ['isTest' => 'true']],
			]],
		];

		$found = [];
		foreach ($flows as $name => $flow) {
			try {
				$found[$name] = ['graph' => $translator->translate(flow: $flow)];
			} catch (EntityNotMigratableException $refusal) {
				$found[$name] = ['refused' => $refusal->getMessage(), 'reasons' => $refusal->getReasons()];
			}
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($found, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(array_keys($golden), array_keys($found));
		foreach ($golden as $name => $expected) {
			$this->assertSame($expected, json_decode((string)json_encode($found[$name]), true), 'case ' . $name);
		}
	}
}
