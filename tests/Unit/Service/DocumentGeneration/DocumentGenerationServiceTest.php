<?php

/**
 * Unit tests for DocumentGenerationService.
 *
 * What the job records, what it refuses to call complete, and what it does
 * when nobody can reach the vendor.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Service\DocumentGeneration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\DocumentGeneration;

use OCA\Integriq\Event\DocumentRenderedEvent;
use OCA\Integriq\Exception\DocumentGenerationException;
use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationProviderInterface;
use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationProviderRegistry;
use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationService;
use OCA\Integriq\Service\DocumentGeneration\RenderOutcome;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the render service.
 */
class DocumentGenerationServiceTest extends TestCase {

	/**
	 * The source row every test renders through.
	 *
	 * @var array<string, mixed>
	 */
	private const SOURCE = [
		'name' => 'SmartDocuments',
		'type' => 'documentGeneration',
		'configuration' => [
			'providerId' => 'smartdocuments',
			'baseUrl' => 'https://vendor.example',
			'mockMode' => false,
			'authentication' => ['credentialRef' => 'cred-1'],
		],
	];

	/**
	 * @var ORObjectService|MockObject
	 */
	private ORObjectService|MockObject $objectService;

	/**
	 * @var IEventDispatcher|MockObject
	 */
	private IEventDispatcher|MockObject $eventDispatcher;

	/**
	 * The objects saveObject() was called with, newest last.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The events dispatched during one test.
	 *
	 * @var array<int, Event>
	 */
	private array $dispatched = [];

	/**
	 * Build a service whose provider is the given double.
	 *
	 * @param DocumentGenerationProviderInterface $provider The binding under test.
	 *
	 * @return DocumentGenerationService The service.
	 */
	private function serviceWith(DocumentGenerationProviderInterface $provider): DocumentGenerationService {
		$registry = $this->getMockBuilder(DocumentGenerationProviderRegistry::class)
			->disableOriginalConstructor()
			->onlyMethods(['resolve', 'all'])
			->getMock();
		$registry->method('resolve')->willReturn($provider);
		$registry->method('all')->willReturn([$provider]);

		return new DocumentGenerationService(
			objectService: $this->objectService,
			registry: $registry,
			eventDispatcher: $this->eventDispatcher,
			logger: new NullLogger()
		);

	}//end serviceWith()

	/**
	 * A provider double. Every method is declared on the real interface.
	 *
	 * @return DocumentGenerationProviderInterface|MockObject The double.
	 */
	private function providerDouble(): DocumentGenerationProviderInterface|MockObject {
		$provider = $this->createMock(DocumentGenerationProviderInterface::class);
		$provider->method('getProviderId')->willReturn('smartdocuments');
		$provider->method('getProviderName')->willReturn('SmartDocuments');

		return $provider;

	}//end providerDouble()

	/**
	 * Set up the object service and the dispatcher, recording both sides.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->saved = [];
		$this->dispatched = [];

		$source = ObjectServiceMockBuilder::objectEntity($this, self::SOURCE, 'source-1');

		$this->objectService = $this->getMockBuilder(ORObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'saveObject', 'findAll'])
			->getMock();
		$this->objectService->method('find')->willReturn($source);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, string $register = '', string $schema = '', ?string $uuid = null): ObjectEntity {
				$this->saved[] = $object;

				return ObjectServiceMockBuilder::objectEntity($this, $object, ($uuid ?? 'job-1'));
			}
		);

		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->eventDispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->dispatched[] = $event;
			}
		);

	}//end setUp()

	/**
	 * The job records a hash of the merge data, and never the data.
	 *
	 * @return void
	 */
	public function testTheJobRecordsAHashAndNeverTheData(): void {
		$provider = $this->providerDouble();
		$provider->method('render')->willReturn(RenderOutcome::queued(providerJobId: 'vendor-1'));

		$this->serviceWith($provider)->requestRender(
			sourceId: 'source-1',
			templateId: 'sd-beschikking',
			data: ['bsn' => '999990019', 'adres' => 'Dorpsstraat 1', 'bedrag' => 1250],
			requestedBy: 'ambtenaar'
		);

		$serialised = json_encode($this->saved);

		$this->assertStringNotContainsString('999990019', $serialised, 'the merge data is not stored');
		$this->assertStringNotContainsString('Dorpsstraat', $serialised);
		$this->assertSame(
			hash('sha256', json_encode(['adres' => 'Dorpsstraat 1', 'bedrag' => 1250, 'bsn' => '999990019'])),
			$this->saved[0]['dataHash'],
			'the hash is over the canonicalised data, so the same data hashes the same'
		);

	}//end testTheJobRecordsAHashAndNeverTheData()

	/**
	 * A vendor nobody could reach leaves the job unreachable, not failed.
	 *
	 * @return void
	 */
	public function testAnUnreachableVendorIsNotReportedAsAFailedRender(): void {
		$provider = $this->providerDouble();
		$provider->method('render')->willThrowException(
			(new DocumentGenerationException(message: 'Connection timed out after 30s'))->asUnreachable()
		);

		$job = $this->serviceWith($provider)->requestRender(
			sourceId: 'source-1',
			templateId: 'sd-beschikking',
			data: ['a' => 1]
		);

		$data = $job->getObject();

		$this->assertSame('unreachable', $data['status']);
		$this->assertSame('', $data['lastError'], 'a vendor that said nothing refused nothing');
		$this->assertNotSame('', $data['unreachableSince']);
		$this->assertSame(
			[],
			$this->dispatched,
			'nothing is announced while nobody knows whether a document exists'
		);

	}//end testAnUnreachableVendorIsNotReportedAsAFailedRender()

	/**
	 * A vendor that refused is recorded as a refusal, with its reason, and announced.
	 *
	 * @return void
	 */
	public function testARefusalIsRecordedWithItsReasonAndAnnounced(): void {
		$provider = $this->providerDouble();
		$provider->method('render')->willReturn(
			RenderOutcome::failed(detail: 'Template sd-beschikking no longer exists', providerJobId: 'vendor-9')
		);

		$job = $this->serviceWith($provider)->requestRender(
			sourceId: 'source-1',
			templateId: 'sd-beschikking',
			data: ['a' => 1]
		);

		$this->assertSame('failed', $job->getObject()['status']);
		$this->assertSame('Template sd-beschikking no longer exists', $job->getObject()['lastError']);
		$this->assertCount(1, $this->dispatched);
		$this->assertInstanceOf(DocumentRenderedEvent::class, $this->dispatched[0]);
		$this->assertSame('failed', $this->dispatched[0]->getStatus());

	}//end testARefusalIsRecordedWithItsReasonAndAnnounced()

	/**
	 * A render is filed as complete only once its document has been fetched whole.
	 *
	 * @return void
	 */
	public function testARenderedDocumentIsFetchedBeforeTheJobIsCalledComplete(): void {
		$provider = $this->providerDouble();
		$provider->method('render')->willReturn(
			RenderOutcome::rendered(providerJobId: 'vendor-1', fileReference: 'doc-1')
		);
		$provider->expects($this->once())->method('fetch')->willReturn('%PDF-1.4 a whole beschikking');

		$job = $this->serviceWith($provider)->requestRender(
			sourceId: 'source-1',
			templateId: 'sd-beschikking',
			data: ['a' => 1],
			requestedBy: 'ambtenaar'
		);

		$data = $job->getObject();

		$this->assertSame('rendered', $data['status']);
		$this->assertSame('doc-1', $data['fileReference']);
		$this->assertSame(28, $data['documentBytes']);
		$this->assertCount(1, $this->dispatched);
		$this->assertSame('doc-1', $this->dispatched[0]->getFileReference());
		$this->assertSame('ambtenaar', $this->dispatched[0]->getRequestedBy());

	}//end testARenderedDocumentIsFetchedBeforeTheJobIsCalledComplete()

	/**
	 * A document that comes back empty is not filed as a document.
	 *
	 * @return void
	 */
	public function testAnEmptyDocumentIsNotFiledAsComplete(): void {
		$provider = $this->providerDouble();
		$provider->method('render')->willReturn(
			RenderOutcome::rendered(providerJobId: 'vendor-1', fileReference: 'doc-1')
		);
		$provider->method('fetch')->willReturn('   ');

		$job = $this->serviceWith($provider)->requestRender(
			sourceId: 'source-1',
			templateId: 'sd-beschikking',
			data: ['a' => 1]
		);

		$data = $job->getObject();

		$this->assertSame('failed', $data['status']);
		$this->assertSame('', $data['fileReference'], 'no reference is kept to a document that is not one');
		$this->assertStringContainsString('empty document', $data['lastError']);

	}//end testAnEmptyDocumentIsNotFiledAsComplete()

	/**
	 * A document reported ready that cannot be fetched yet is unfinished, not failed.
	 *
	 * @return void
	 */
	public function testADocumentThatCannotBeFetchedYetLeavesTheJobUnfinished(): void {
		$provider = $this->providerDouble();
		$provider->method('render')->willReturn(
			RenderOutcome::rendered(providerJobId: 'vendor-1', fileReference: 'doc-1')
		);
		$provider->method('fetch')->willThrowException(
			(new DocumentGenerationException(message: 'Connection reset'))->asUnreachable()
		);

		$job = $this->serviceWith($provider)->requestRender(
			sourceId: 'source-1',
			templateId: 'sd-beschikking',
			data: ['a' => 1]
		);

		$data = $job->getObject();

		$this->assertSame('unreachable', $data['status']);
		$this->assertSame([], $this->dispatched);

	}//end testADocumentThatCannotBeFetchedYetLeavesTheJobUnfinished()

	/**
	 * Polling a settled job asks the vendor nothing.
	 *
	 * @return void
	 */
	public function testPollingASettledJobAsksTheVendorNothing(): void {
		$provider = $this->providerDouble();
		$provider->expects($this->never())->method('status');

		$job = ObjectServiceMockBuilder::objectEntity(
			$this,
			['status' => 'rendered', 'providerJobId' => 'vendor-1', 'sourceId' => 'source-1'],
			'job-1'
		);

		$this->serviceWith($provider)->pollJob(job: $job);

	}//end testPollingASettledJobAsksTheVendorNothing()

	/**
	 * Polling an unreachable job that the vendor now answers settles it.
	 *
	 * @return void
	 */
	public function testPollingCanSettleAJobTheVendorWentQuietOn(): void {
		$provider = $this->providerDouble();
		$provider->method('status')->willReturn(
			RenderOutcome::rendered(providerJobId: 'vendor-1', fileReference: 'doc-7')
		);
		$provider->method('fetch')->willReturn('%PDF-1.4 the document after all');

		$job = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'status' => 'unreachable',
				'providerJobId' => 'vendor-1',
				'sourceId' => 'source-1',
				'unreachableSince' => '2026-09-18T10:00:00+00:00',
			],
			'job-1'
		);

		$settled = $this->serviceWith($provider)->pollJob(job: $job)->getObject();

		$this->assertSame('rendered', $settled['status']);
		$this->assertSame('doc-7', $settled['fileReference']);
		$this->assertSame('', $settled['unreachableSince'], 'the vendor answered, so it is not unreachable any more');
		$this->assertCount(1, $this->dispatched);

	}//end testPollingCanSettleAJobTheVendorWentQuietOn()

	/**
	 * A job the vendor never named cannot be polled, and nothing is invented for it.
	 *
	 * @return void
	 */
	public function testAJobTheVendorNeverNamedIsNotPolled(): void {
		$provider = $this->providerDouble();
		$provider->expects($this->never())->method('status');

		$job = ObjectServiceMockBuilder::objectEntity(
			$this,
			['status' => 'unreachable', 'providerJobId' => '', 'sourceId' => 'source-1'],
			'job-1'
		);

		$polled = $this->serviceWith($provider)->pollJob(job: $job)->getObject();

		$this->assertSame('unreachable', $polled['status']);

	}//end testAJobTheVendorNeverNamedIsNotPolled()
}//end class
