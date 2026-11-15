<?php

/**
 * Golden master of RuleToFlowGenerator::refusalsFor().
 *
 * Every case pairs a rule with the endpoint that runs it and records every
 * refusal sentence the generator gives, in order. Set RECORD_GOLDEN=1 to
 * rewrite the fixture.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\MigrationEntityReader;
use OCA\Integriq\Service\MigrationSubject;
use OCA\Integriq\Service\RuleConditionTranslator;
use OCA\Integriq\Service\RuleEndpointScope;
use OCA\Integriq\Service\RuleToFlowGenerator;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Service\RuleToFlowGenerator
 */
class RuleToFlowRefusalsGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/../../fixtures/golden/rule-to-flow-refusals.json';

	/**
	 * The generator over doubles, with translations rendered.
	 *
	 * @return RuleToFlowGenerator The generator.
	 */
	private function generator(): RuleToFlowGenerator {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, $parameters = []): string {
				if (is_array($parameters) === false || $parameters === []) {
					return $text;
				}

				return vsprintf($text, $parameters);
			}
		);

		return new RuleToFlowGenerator(
			reader: new MigrationEntityReader(objectService: $this->createMock(ORObjectService::class), l10n: $l10n),
			conditions: new RuleConditionTranslator(),
			scope: new RuleEndpointScope(subject: new MigrationSubject()),
			subject: new MigrationSubject(),
			l10n: $l10n
		);
	}

	/**
	 * Every case: the rule overrides and the endpoint overrides.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	private static function cases(): array {
		return [
			'migratable' => [[], []],
			'no-references-anywhere' => [['uuid' => null], ['uuid' => null]],
			'endpoint-does-not-list-rule' => [[], ['rules' => ['someone-else']]],
			'endpoint-target-type' => [[], ['targetType' => 'source', 'targetId' => 'x']],
			'endpoint-target-not-a-pair' => [[], ['targetId' => 'spectr']],
			'endpoint-target-empty' => [[], ['targetId' => '  ']],
			'endpoint-read-method' => [[], ['method' => 'GET']],
			'everything-wrong-in-scope' => [['uuid' => null], ['uuid' => null, 'rules' => [], 'targetType' => 'url', 'method' => 'OPTIONS']],
			'timing-before' => [['timing' => 'before'], []],
			'timing-default' => [['timing' => null], []],
			'conditions-empty-string' => [['conditions' => ''], []],
			'conditions-empty-array' => [['conditions' => []], []],
			'conditions-scalar' => [['conditions' => 7], []],
			'conditions-sub-scope' => [['conditions' => ['some' => [['var' => 'body.items'], ['==' => [['var' => 'id'], 1]]]]], []],
			'conditions-request-paths' => [['conditions' => ['and' => [['==' => [['var' => 'headers.x-api-key'], 'k']], ['==' => [['var' => 'query.page'], 1]]]]], []],
			'conditions-both' => [['conditions' => ['and' => [['all' => [['var' => 'body.items'], ['var' => 'ok']]], ['var' => 'path.id']]]], []],
			'type-envelope' => [['type' => 'mapping'], []],
			'type-missing' => [['type' => null], []],
			'flow-rule-ok' => [['type' => 'flow', 'configuration' => ['flow' => 'f-1']], []],
			'flow-rule-missing' => [['type' => 'flow', 'configuration' => ['flow' => '  ']], []],
			'sync-missing' => [['configuration' => []], []],
			'sync-block-keys' => [['configuration' => ['synchronization' => ['id' => 's-1', 'preDelay' => 5, 'retainResponse' => true]]], []],
			'sync-block-clean' => [['configuration' => ['synchronization' => ['id' => 's-1']]], []],
			'sync-is-test' => [['configuration' => ['synchronization' => 's-1', 'isTest' => 'true']], []],
			'sync-is-test-false' => [['configuration' => ['synchronization' => 's-1', 'isTest' => 'no']], []],
			'sync-config' => [['configuration' => ['synchronization' => 's-1', 'synchronizationConfig' => ['merge' => true]]], []],
			'sync-everything' => [['configuration' => ['synchronization' => ['objectIdPath' => 'id', 'postDelay' => 1], 'isTest' => true, 'synchronizationConfig' => ['x' => 1]]], []],
		];
	}

	/**
	 * Every case against the recorded fixture.
	 *
	 * @return void
	 */
	public function testTheRefusalsMatchTheRecording(): void {
		$generator = $this->generator();
		$found = [];
		foreach (self::cases() as $name => [$rule, $endpoint]) {
			$found[$name] = $generator->refusalsFor(
				rule: array_merge(
					[
						'uuid' => 'r0000000-0000-4000-8000-000000000001',
						'name' => 'Publish tender downstream',
						'type' => 'synchronization',
						'timing' => 'after',
						'conditions' => ['==' => [['var' => 'body.status'], 'published']],
						'configuration' => ['synchronization' => 'tender-to-downstream'],
					],
					$rule
				),
				endpoint: array_merge(
					[
						'uuid' => 'e0000000-0000-4000-8000-000000000001',
						'name' => 'Create tender',
						'method' => 'POST',
						'targetType' => 'register/schema',
						'targetId' => 'spectr/tender',
						'rules' => ['r0000000-0000-4000-8000-000000000001'],
					],
					$endpoint
				)
			);
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($found, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(array_keys($golden), array_keys($found));
		foreach ($golden as $name => $expected) {
			$this->assertSame($expected, $found[$name], 'case ' . $name);
		}
	}
}
