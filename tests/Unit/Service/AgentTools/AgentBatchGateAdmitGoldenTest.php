<?php

/**
 * Characterisation test for AgentBatchGate::admit.
 *
 * Pins which staged batches an approval admits and which it refuses, and what
 * is stored either way. Times are placed relative to now and masked.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\AgentTools
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\AgentTools;

use OCA\Integriq\Service\AgentTools\AgentActionRefusedException;
use OCA\Integriq\Service\AgentTools\AgentActionStore;
use OCA\Integriq\Service\AgentTools\AgentBatchGate;
use OCA\Integriq\Service\AgentTools\ApprovalVerdictVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Golden record of the batch admission.
 */
class AgentBatchGateAdmitGoldenTest extends TestCase {

	/**
	 * Replace clock values by markers.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private static function mask(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		foreach (['executedAt', 'at'] as $key) {
			if (isset($value[$key]) === true && is_string($value[$key]) === true && strtotime($value[$key]) !== false) {
				$value[$key] = '<time>';
			}
		}

		return array_map([self::class, 'mask'], $value);
	}//end mask()

	/**
	 * The recorded admissions.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$fresh = gmdate(DATE_ATOM, time() - 60);
		$stale = gmdate(DATE_ATOM, time() - AgentBatchGate::PROPOSAL_TTL - 60);
		$record = ['tool' => 'objects.update', 'store' => 'zaken', 'agent' => 'agent-1', 'grantingUser' => 'alice', 'targetIds' => ['b', 'a'], 'at' => $fresh];
		$staged = ['outcome' => 'staged', 'binding' => 'bind-1', 'targetIds' => ['a', 'b']] + $record;
		$cases = [
			'admitted' => [$staged, $record, 'ok'],
			'no proposal' => [null, $record, 'ok'],
			'not staged' => [['outcome' => 'executed'] + $staged, $record, 'ok'],
			'other tool' => [$staged, ['tool' => 'objects.delete'] + $record, 'ok'],
			'other granting user' => [$staged, ['grantingUser' => 'bob'] + $record, 'ok'],
			'missing field on both' => [array_diff_key($staged, ['store' => 1]), array_diff_key($record, ['store' => 1]), 'ok'],
			'other targets' => [$staged, ['targetIds' => ['a', 'c']] + $record, 'ok'],
			'fewer targets' => [$staged, ['targetIds' => ['a']] + $record, 'ok'],
			'expired' => [['at' => $stale] + $staged, $record, 'ok'],
			'unreadable time' => [['at' => 'soon'] + $staged, $record, 'ok'],
			'no time' => [array_diff_key($staged, ['at' => 1]), $record, 'ok'],
			'approval refused' => [$staged, $record, 'refuse'],
			'no binding stored' => [array_diff_key($staged, ['binding' => 1]), $record, 'ok'],
		];

		$result = [];
		foreach ($cases as $name => [$proposal, $asked, $verdict]) {
			$calls = [];
			$store = $this->createMock(AgentActionStore::class);
			$store->method('find')->willReturnCallback(
				static function (string $uuid) use (&$calls, $proposal): ?array {
					$calls[] = ['find' => $uuid];
					return $proposal;
				}
			);
			$store->method('record')->willReturnCallback(
				static function (array $record) use (&$calls): string {
					$calls[] = ['record' => $record];
					return 'refusal-1';
				}
			);
			$store->method('update')->willReturnCallback(
				static function (string $uuid, array $record) use (&$calls): void {
					$calls[] = ['update' => [$uuid, $record]];
				}
			);
			$verifier = $this->createMock(ApprovalVerdictVerifier::class);
			$verifier->method('verify')->willReturnCallback(
				static function (string $approvalId, string $toolId, string $binding, string $actingAgent) use (&$calls, $verdict): string {
					$calls[] = ['verify' => [$approvalId, $toolId, $binding, $actingAgent]];
					if ($verdict === 'refuse') {
						throw new AgentActionRefusedException(reason: 'approval-not-granted');
					}

					return 'approver-1';
				}
			);

			try {
				$answer = ['admitted' => (new AgentBatchGate($store, $verifier))->admit('prop-1', 'appr-1', $asked)];
			} catch (AgentActionRefusedException $e) {
				$answer = ['refused' => $e->reason];
			}

			$result[$name] = self::mask(value: $answer + ['calls' => $calls]);
		}

		$path = __DIR__ . '/../../../fixtures/agent-tools/batch-gate-admit.json';
		$actual = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
