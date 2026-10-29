<?php

/**
 * Unit tests: recording validation findings rewrites a call log without secret headers.
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
 * EndpointMessageGate::record() writes the call log handed to it back to the
 * register; the answer's secret headers must not be written with it.
 */
class EndpointMessageGateRecordTest extends TestCase {

	/**
	 * Recording findings stores the findings and masks the answer's secret headers.
	 *
	 * @return void
	 */
	public function testRecordingFindingsStoresNoSecretResponseHeader(): void {
		$written = null;
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->once())->method('saveObject')->willReturnCallback(
			function ($object = []) use (&$written) {
				$written = $object;
				return new ObjectEntity();
			}
		);

		$callLog = new ObjectEntity();
		$callLog->setUuid('0d1c2b3a-4e5f-4a6b-8c7d-9e0f1a2b3c4d');
		$callLog->setObject(
			[
				'statusCode' => 200,
				'response' => [
					'headers' => [
						'Content-Type' => ['application/json'],
						'Set-Cookie' => ['session=abc123secret', 'csrf=def456secret'],
						'X-Auth-TOKEN' => ['mixed-case-token-value'],
					],
					'body' => '{"ok":true}',
				],
			]
		);

		$gate = new EndpointMessageGate($this->createMock(MessageValidationService::class), $objects, new NullLogger());
		$gate->record(callLog: $callLog, findings: [['error' => 'name is required']], endpoint: 'endpoint-1');

		$placeholder = SensitiveFieldRegistry::PLACEHOLDER;
		$this->assertSame([['error' => 'name is required']], $written['validation']);
		$this->assertSame([$placeholder, $placeholder], $written['response']['headers']['Set-Cookie']);
		$this->assertSame([$placeholder], $written['response']['headers']['X-Auth-TOKEN']);
		$this->assertSame(['application/json'], $written['response']['headers']['Content-Type']);
		$this->assertSame(200, $written['statusCode']);
	}//end testRecordingFindingsStoresNoSecretResponseHeader()
}//end class
