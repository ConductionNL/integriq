<?php

/**
 * Coverage tests for OsoService's failure paths.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-09-29-integriq-adapter-oso/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\OsoProviderException;
use OCA\Integriq\Service\Oso\OsoAcknowledgementTranslator;
use OCA\Integriq\Service\Oso\OsoExportEnvelopeTranslator;
use OCA\Integriq\Service\Oso\OsoImportTranslator;
use OCA\Integriq\Service\Oso\OsoProviderInterface;
use OCA\Integriq\Service\Oso\OsoProviderRegistry;
use OCA\Integriq\Service\OsoService;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the transport, parse and retry failure paths of the OSO bridge.
 *
 * @covers \OCA\Integriq\Service\OsoService
 * @uses   \OCA\Integriq\Exception\OsoProviderException
 * @uses   \OCA\Integriq\Exception\OsoTranslationException
 * @uses   \OCA\Integriq\Service\Helper\ProviderIds
 * @uses   \OCA\Integriq\Service\Helper\ProviderIndex
 * @uses   \OCA\Integriq\Service\Oso\OsoAcknowledgementTranslator
 * @uses   \OCA\Integriq\Service\Oso\OsoExportEnvelopeTranslator
 * @uses   \OCA\Integriq\Service\Oso\OsoImportTranslator
 * @uses   \OCA\Integriq\Service\Oso\OsoProviderRegistry
 * @uses   \OCA\Integriq\Service\Security\ActiveSourceLookup
 * @uses   \OCA\Integriq\Service\Security\RawSourceResolver
 * @uses   \OCA\Integriq\Service\Stuf\EnvelopeElement
 * @uses   \OCA\Integriq\Service\Stuf\ExportEnvelope
 * @uses   \OCA\Integriq\Service\Stuf\RequiredPayloadFields
 * @uses   \OCA\Integriq\Service\Stuf\RetourAcknowledgementReader
 * @uses   \OCA\Integriq\Service\Stuf\StufLiteralLeakGuard
 * @uses   \OCA\Integriq\Service\Stuf\StufXmlParser
 * @uses   \OCA\Integriq\Util\SafeXmlParser
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/oso-adapter/spec.md
 */
class OsoServiceCoverageTest extends TestCase {

	/**
	 * @var ORObjectService&MockObject
	 */
	private $objectService;

	/**
	 * @var OsoProviderInterface&MockObject
	 */
	private $provider;

	/**
	 * @var IEventDispatcher&MockObject
	 */
	private $eventDispatcher;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * @var OsoService
	 */
	private OsoService $service;

	/**
	 * @var array<int, ObjectEntity>
	 */
	private array $messages = [];

	/**
	 * Every saveObject() call: object, register, schema, uuid.
	 *
	 * @var array<int, array{object: array, register: mixed, schema: mixed, uuid: mixed}>
	 */
	private array $saved = [];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->messages = [];
		$this->saved = [];

		$this->objectService = $this->getMockBuilder(ORObjectService::class)
			->disableOriginalConstructor()
			->getMock();
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				$schema = ($config['filters']['schema'] ?? null);
				if ($schema === OsoService::SCHEMA_SOURCE) {
					// Uuid-less source: RawSourceResolver hands it back as-is.
					return ['results' => [ObjectServiceMockBuilder::objectEntity($this, ['type' => 'oso', 'isEnabled' => true, 'configuration' => ['provider' => 'log', 'endpoint' => 'https://oso.example']], '')]];
				}

				return ['results' => $this->messages];
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function ($object, $register = null, $schema = null, $uuid = null): ObjectEntity {
				$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return ObjectServiceMockBuilder::objectEntity($this, $object, ($uuid ?? 'saved'));
			}
		);

		$this->provider = $this->createMock(OsoProviderInterface::class);
		$this->provider->method('getProviderId')->willReturn('log');

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);

		$this->service = new OsoService(
			objectService: $this->objectService,
			providers: new OsoProviderRegistry([$this->provider]),
			exportTranslator: new OsoExportEnvelopeTranslator(),
			importTranslator: new OsoImportTranslator(),
			ackTranslator: new OsoAcknowledgementTranslator(),
			eventDispatcher: $this->eventDispatcher,
			l: $l,
			logger: $this->logger,
			rawSourceResolver: new RawSourceResolver($this->objectService, $this->logger)
		);

	}//end setUp()

	/**
	 * A transport failure persists a `failed` record carrying the error and the
	 * kenmerk as ref, then rethrows as OsoProviderException.
	 *
	 * @return void
	 */
	public function testSendExportPersistsFailedRecordAndRethrowsOnTransportFailure(): void {
		$this->provider->expects($this->once())
			->method('sendExport')
			->with(
				['provider' => 'log', 'endpoint' => 'https://oso.example'],
				'kenmerk-42',
				$this->stringContains('kenmerk-42')
			)
			->willThrowException(new OsoProviderException(message: 'Kennisnet endpoint returned 503'));
		$this->eventDispatcher->expects($this->never())->method('dispatchTyped');

		try {
			$this->service->sendExport(
				'kenmerk-42',
				['learnerEckId' => 'eck-id-42', 'targetSchoolBrin' => '34CD', 'categories' => [['category' => 'basisgegevens', 'included' => true]]]
			);
			$this->fail('Expected OsoProviderException was not thrown.');
		} catch (OsoProviderException $exception) {
			$this->assertSame('Kennisnet endpoint returned 503', $exception->getMessage());
		}

		$this->assertCount(1, $this->saved);
		$record = $this->saved[0];
		$this->assertSame(OsoService::REGISTER, $record['register']);
		$this->assertSame(OsoService::SCHEMA_MESSAGE, $record['schema']);
		$this->assertSame('export', $record['object']['direction']);
		$this->assertSame('failed', $record['object']['status']);
		$this->assertSame('kenmerk-42', $record['object']['ref']);
		$this->assertSame('kenmerk-42', $record['object']['kenmerk']);
		$this->assertSame('eck-id-42', $record['object']['learnerEckId']);
		$this->assertSame('Kennisnet endpoint returned 503', $record['object']['error']);

	}//end testSendExportPersistsFailedRecordAndRethrowsOnTransportFailure()

	/**
	 * An unparseable inbound dossier is logged and dropped: nothing persisted, no event.
	 *
	 * @return void
	 */
	public function testReceiveImportDropsUnparseableDossier(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'OSO inbound dossier could not be parsed; dropped',
				['exception' => 'Inbound OSO dossier is not well-formed XML.']
			);
		$this->objectService->expects($this->never())->method('saveObject');
		$this->eventDispatcher->expects($this->never())->method('dispatchTyped');

		$this->service->receiveImport('<OsoOverstapdossier><unclosed>');

	}//end testReceiveImportDropsUnparseableDossier()

	/**
	 * A dossier without a sending BRIN is refused by the translator and dropped.
	 *
	 * @return void
	 */
	public function testReceiveImportDropsDossierWithoutBrin(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with('OSO inbound dossier could not be parsed; dropped', $this->arrayHasKey('exception'));
		$this->objectService->expects($this->never())->method('saveObject');
		$this->eventDispatcher->expects($this->never())->method('dispatchTyped');

		$this->service->receiveImport((string)file_get_contents(__DIR__ . '/../../fixtures/oso/import-no-brin.xml'));

	}//end testReceiveImportDropsDossierWithoutBrin()

	/**
	 * An untranslatable retour is logged and dropped: nothing persisted, no event.
	 *
	 * @return void
	 */
	public function testReceiveReturnDropsUntranslatableRetour(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with('OSO retour could not be translated; dropped', $this->arrayHasKey('exception'));
		$this->objectService->expects($this->never())->method('saveObject');
		$this->eventDispatcher->expects($this->never())->method('dispatchTyped');

		$this->service->receiveReturn((string)file_get_contents(__DIR__ . '/../../fixtures/oso/retour-no-kenmerk.xml'));

	}//end testReceiveReturnDropsUntranslatableRetour()

	/**
	 * One failing retry is logged and skipped; the sweep continues and counts
	 * only the message that went through, which alone is re-saved as `sent`.
	 *
	 * @return void
	 */
	public function testRetryFailedSkipsFailingMessageAndContinues(): void {
		$this->messages = [
			ObjectServiceMockBuilder::objectEntity($this, ['direction' => 'export', 'kenmerk' => 'k-bad', 'status' => 'failed', 'ref' => 'REF-BAD'], 'msg-bad'),
			ObjectServiceMockBuilder::objectEntity($this, ['direction' => 'export', 'kenmerk' => 'k-ok', 'status' => 'pending', 'ref' => 'REF-OK', 'error' => 'timeout'], 'msg-ok'),
		];

		$this->provider->expects($this->exactly(2))
			->method('sendExport')
			->willReturnCallback(
				static function (array $sourceConfiguration, string $kenmerk, string $envelopeXml): string {
					if ($kenmerk === 'k-bad') {
						throw new OsoProviderException(message: 'still down');
					}

					return 'REF-OK';
				}
			);
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'OSO retry failed for one message; skipped, sweep continues',
				['ref' => 'REF-BAD', 'exception' => 'still down']
			);

		$this->assertSame(1, $this->service->retryFailed());

		$this->assertCount(1, $this->saved);
		$this->assertSame('msg-ok', $this->saved[0]['uuid']);
		$this->assertSame('sent', $this->saved[0]['object']['status']);
		$this->assertNull($this->saved[0]['object']['error']);
		$this->assertSame('k-ok', $this->saved[0]['object']['kenmerk']);

	}//end testRetryFailedSkipsFailingMessageAndContinues()
}//end class
