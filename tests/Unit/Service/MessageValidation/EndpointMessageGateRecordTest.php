<?php

/**
 * Unit tests: recording validation findings rewrites a call log as it was stored.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\MessageValidation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\MessageValidation;

use OCA\Integriq\Service\MessageValidation\EndpointMessageGate;
use OCA\Integriq\Service\MessageValidationService;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * EndpointMessageGate::record() adds findings to the call log row as the call
 * stored it. The entity a call hands back carries the full answer (its body and
 * its real headers); none of that may reach the stored row.
 */
class EndpointMessageGateRecordTest extends TestCase {

	private const UUID = '0d1c2b3a-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

	/**
	 * The call log row as the call stored it: headers redacted, no body.
	 *
	 * @var array<string,mixed>
	 */
	private const STORED = [
		'statusCode' => 200,
		'direction' => 'outbound',
		'bodyCaptured' => false,
		'response' => [
			'statusCode' => 200,
			'headers' => [
				'Content-Type' => ['application/json'],
				'Set-Cookie' => [SensitiveFieldRegistry::PLACEHOLDER, SensitiveFieldRegistry::PLACEHOLDER],
			],
		],
	];

	/**
	 * What the gate wrote, or null when it wrote nothing.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $written = null;

	/**
	 * Record one finding on the call log the call handed back.
	 *
	 * @param array<string,mixed>|null $stored The stored row the register answers, or null when it cannot be read.
	 *
	 * @return void
	 */
	private function record(?array $stored): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function ($id, ?string $register = null, ?string $schema = null) use ($stored): ?ObjectEntity {
				if ($stored === null || $schema !== 'call_log' || (string)$id !== self::UUID) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid(self::UUID);
				$entity->setObject($stored);
				return $entity;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object = [], ?string $register = null, ?string $schema = null, ?string $uuid = null) {
				$this->assertSame('call_log', $schema);
				$this->assertSame(self::UUID, $uuid);
				$this->written = $object;
				return new ObjectEntity();
			}
		);

		$handedBack = new ObjectEntity();
		$handedBack->setUuid(self::UUID);
		$handedBack->setObject(
			[
				'statusCode' => 200,
				'direction' => 'outbound',
				'bodyCaptured' => false,
				'response' => [
					'statusCode' => 200,
					'headers' => [
						'Content-Type' => ['application/json'],
						'Set-Cookie' => ['session=abc123secret', 'csrf=def456secret'],
					],
					'body' => '{"bsn":"999993653"}',
				],
			]
		);

		$gate = new EndpointMessageGate($this->createMock(MessageValidationService::class), $objects, new NullLogger());
		$gate->record(callLog: $handedBack, findings: [['error' => 'name is required']], endpoint: 'endpoint-1');
	}//end record()

	/**
	 * The findings are added to the stored row; the answer's body and secret headers are not written.
	 *
	 * @return void
	 */
	public function testRecordingFindingsKeepsTheStoredRowAndAddsTheFindings(): void {
		$this->record(stored: self::STORED);

		$this->assertSame(array_merge(self::STORED, ['validation' => [['error' => 'name is required']]]), $this->written);
		$encoded = (string)json_encode($this->written);
		$this->assertStringNotContainsString('999993653', $encoded);
		$this->assertStringNotContainsString('abc123secret', $encoded);
	}//end testRecordingFindingsKeepsTheStoredRowAndAddsTheFindings()

	/**
	 * When the stored row cannot be read, nothing is written: the answer is never stored in its place.
	 *
	 * @return void
	 */
	public function testAnUnreadableStoredRowIsNotReplacedByTheAnswer(): void {
		$this->record(stored: null);

		$this->assertNull($this->written);
	}//end testAnUnreadableStoredRowIsNotReplacedByTheAnswer()
}//end class
