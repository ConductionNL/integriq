<?php

/**
 * Characterisation tests for NotuBizConnectorService::pushVergaderstuk().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\AuthenticationService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\NotuBizConnectorService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every answer, request and log line of pushVergaderstuk().
 *
 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-8
 */
class NotuBizPushVergaderstukGoldenTest extends TestCase {

	/**
	 * @var CallService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $callService;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * Log lines written, as [level, message, context].
	 *
	 * @var list<array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $logs = [];

	/**
	 * @var NotuBizConnectorService
	 */
	private NotuBizConnectorService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->callService = $this->createMock(CallService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error', 'warning'] as $level) {
			$this->logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		$this->service = new NotuBizConnectorService(
			$this->callService,
			$this->createMock(AuthenticationService::class),
			$this->logger
		);

	}//end setUp()

	/**
	 * A source with an organisation and an optional default vergadertype.
	 *
	 * @param array<string,mixed> $configuration The configuration.
	 *
	 * @return ObjectEntity
	 */
	private function source(array $configuration): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity($this, ['configuration' => $configuration], 'notubiz-golden');

	}//end source()

	/**
	 * A call log answering with a status and a body.
	 *
	 * @param int   $status The status code.
	 * @param mixed $body   The response body.
	 *
	 * @return ObjectEntity
	 */
	private function callLog(int $status, mixed $body): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity(
			$this,
			['statusCode' => $status, 'response' => ['body' => $body]],
			'notubiz-golden-log'
		);

	}//end callLog()

	/**
	 * A source configured as a JSON string without an organisation is refused, and nothing is called.
	 *
	 * @return void
	 */
	public function testMissingOrganisationIsRefusedWithoutACall(): void {
		$this->callService->expects($this->never())->method('call');

		$result = $this->service->pushVergaderstuk($this->source(['clientId' => 'x']), ['onderwerp' => 'A']);

		$this->assertSame(
			['success' => false, 'message' => 'Organisation ID not configured', 'vergaderstukId' => null],
			$result
		);
		$this->assertSame([], $this->logs);

	}//end testMissingOrganisationIsRefusedWithoutACall()

	/**
	 * The payload, endpoint and method sent, with the default vergadertype and meeting id.
	 *
	 * @return void
	 */
	public function testRequestCarriesThePayload(): void {
		$this->callService->expects($this->once())->method('call')
			->with(
				$this->anything(),
				'/api/v1/organisations/org-1/vergaderstukken',
				'POST',
				[
					'json' => [
						'onderwerp' => 'Begroting',
						'vergadertype' => 'commissievergadering',
						'zaaktype' => 'zt-1',
						'vertrouwelijk' => true,
						'vergaderingId' => 'meeting-9',
					],
				]
			)
			->willReturn($this->callLog(status: 201, body: json_encode(['stukId' => 'stuk-2'])));

		$result = $this->service->pushVergaderstuk(
			$this->source(['organisatieId' => 'org-1', 'defaultVergaderType' => 'Commissie']),
			['onderwerp' => 'Begroting', 'zaaktype' => 'zt-1', 'geheimhouding' => 1],
			'meeting-9'
		);

		$this->assertSame(
			['success' => true, 'message' => 'Vergaderstuk pushed to NotuBiz', 'vergaderstukId' => 'stuk-2'],
			$result
		);
		$this->assertSame(
			[['info', 'NotuBiz: Pushing vergaderstuk', ['onderwerp' => 'Begroting', 'vergadertype' => 'commissievergadering']]],
			$this->logs
		);

	}//end testRequestCarriesThePayload()

	/**
	 * A payload without fields gets the blank defaults and no meeting.
	 *
	 * @return void
	 */
	public function testBlankPayloadDefaults(): void {
		$this->callService->expects($this->once())->method('call')
			->with(
				$this->anything(),
				$this->anything(),
				'POST',
				[
					'json' => [
						'onderwerp' => '',
						'vergadertype' => 'collegevergadering',
						'zaaktype' => '',
						'vertrouwelijk' => false,
						'vergaderingId' => null,
					],
				]
			)
			->willReturn($this->callLog(status: 200, body: 'not json'));

		$result = $this->service->pushVergaderstuk($this->source(['organisatieId' => 'org-1']), []);

		$this->assertSame(
			['success' => true, 'message' => 'Vergaderstuk pushed to NotuBiz', 'vergaderstukId' => null],
			$result
		);

	}//end testBlankPayloadDefaults()

	/**
	 * Each refused status maps to its message and one error line.
	 *
	 * @return array<string,array{0:int,1:string}>
	 */
	public static function refusedStatuses(): array {
		return [
			'no response' => [0, 'API call failed — no response'],
			'unauthorised' => [401, 'Authentication failed — token may need refresh'],
			'too large' => [413, 'Vergaderstuk too large'],
			'other' => [500, 'Upload failed with status 500'],
			'redirect' => [302, 'Upload failed with status 302'],
			'informational' => [199, 'Upload failed with status 199'],
		];

	}//end refusedStatuses()

	/**
	 * A status outside 2xx is a failure with a status-specific message.
	 *
	 * @param int    $status  The status.
	 * @param string $message The expected message.
	 *
	 * @return void
	 */
	#[DataProvider('refusedStatuses')]
	public function testRefusedStatus(int $status, string $message): void {
		$this->callService->method('call')->willReturn($this->callLog(status: $status, body: '{"id":"x"}'));

		$result = $this->service->pushVergaderstuk($this->source(['organisatieId' => 'org-1']), ['onderwerp' => 'B']);

		$this->assertSame(['success' => false, 'message' => $message, 'vergaderstukId' => null], $result);
		$this->assertSame(
			['error', 'NotuBiz: vergaderstuk push failed', ['status' => $status, 'onderwerp' => 'B']],
			$this->logs[1]
		);
		$this->assertCount(2, $this->logs);

	}//end testRefusedStatus()

	/**
	 * An array body answers its `id` before `stukId`.
	 *
	 * @return void
	 */
	public function testIdWinsOverStukId(): void {
		$this->callService->method('call')->willReturn($this->callLog(status: 299, body: ['id' => 'a', 'stukId' => 'b']));

		$result = $this->service->pushVergaderstuk($this->source(['organisatieId' => 'org-1']), []);

		$this->assertSame('a', $result['vergaderstukId']);
		$this->assertTrue($result['success']);

	}//end testIdWinsOverStukId()

	/**
	 * An exception from the call is a failure with its message, logged once.
	 *
	 * @return void
	 */
	public function testExceptionIsAFailure(): void {
		$this->callService->method('call')->willThrowException(new RuntimeException('boom'));

		$result = $this->service->pushVergaderstuk($this->source(['organisatieId' => 'org-1']), []);

		$this->assertSame(['success' => false, 'message' => 'Push failed: boom', 'vergaderstukId' => null], $result);
		$this->assertSame(['error', 'NotuBiz: push exception', ['exception' => 'boom']], $this->logs[1]);

	}//end testExceptionIsAFailure()

	/**
	 * A configuration stored as a JSON string is decoded.
	 *
	 * @return void
	 */
	public function testJsonStringConfigurationIsDecoded(): void {
		$source = ObjectServiceMockBuilder::objectEntity(
			$this,
			['configuration' => json_encode(['organisatieId' => 'org-7'])],
			'notubiz-golden-json'
		);
		$this->callService->expects($this->once())->method('call')
			->with($this->anything(), '/api/v1/organisations/org-7/vergaderstukken')
			->willReturn($this->callLog(status: 201, body: null));

		$result = $this->service->pushVergaderstuk($source, []);

		$this->assertTrue($result['success']);

	}//end testJsonStringConfigurationIsDecoded()
}//end class
