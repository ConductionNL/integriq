<?php

/**
 * Unit tests for the document generation service's refusal paths.
 *
 * A vendor that refuses a render, a binding that throws something unexpected,
 * a rendered document the vendor will not hand over, a poll whose source has
 * gone, and a render request naming no source, an unknown source or a source
 * of another type.
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
 * @spec openspec/specs/document-generation-vendor-adapter/spec.md
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the paths the main service test leaves out.
 */
#[CoversClass(DocumentGenerationService::class)]
#[UsesClass(RenderOutcome::class)]
#[UsesClass(DocumentGenerationException::class)]
#[UsesClass(DocumentRenderedEvent::class)]
class DocumentGenerationServiceGapsTest extends TestCase {

	/**
	 * A document generation source.
	 *
	 * @var array<string, mixed>
	 */
	private const SOURCE = [
		'name' => 'SmartDocuments',
		'type' => 'documentGeneration',
		'configuration' => [
			'providerId' => 'smartdocuments',
			'baseUrl' => 'https://vendor.example',
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
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface|MockObject $logger;

	/**
	 * The source find() answers with, or null for none.
	 *
	 * @var ObjectEntity|null
	 */
	private ?ObjectEntity $source = null;

	/**
	 * Every object saved, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Every event dispatched, in order.
	 *
	 * @var array<int, Event>
	 */
	private array $dispatched = [];

	/**
	 * Set up the object service, dispatcher and logger doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->saved = [];
		$this->dispatched = [];
		$this->source = ObjectServiceMockBuilder::objectEntity($this, self::SOURCE, 'source-1');

		$this->objectService = $this->getMockBuilder(ORObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'saveObject'])
			->getMock();
		$this->objectService->method('find')->willReturnCallback(fn () => $this->source);
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

		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The service over a registry that resolves to the given provider.
	 *
	 * @param DocumentGenerationProviderInterface $provider The provider.
	 *
	 * @return DocumentGenerationService The service.
	 */
	private function serviceWith(DocumentGenerationProviderInterface $provider): DocumentGenerationService {
		$registry = $this->getMockBuilder(DocumentGenerationProviderRegistry::class)
			->disableOriginalConstructor()
			->onlyMethods(['resolve'])
			->getMock();
		$registry->method('resolve')->willReturn($provider);

		return new DocumentGenerationService(
			objectService: $this->objectService,
			registry: $registry,
			eventDispatcher: $this->eventDispatcher,
			logger: $this->logger
		);

	}//end serviceWith()

	/**
	 * A provider double named smartdocuments.
	 *
	 * @return DocumentGenerationProviderInterface|MockObject The double.
	 */
	private function providerDouble(): DocumentGenerationProviderInterface|MockObject {
		$provider = $this->createMock(DocumentGenerationProviderInterface::class);
		$provider->method('getProviderId')->willReturn('smartdocuments');

		return $provider;

	}//end providerDouble()

	/**
	 * A vendor refusal thrown by the binding is a failed render with the refusal as the error.
	 *
	 * @return void
	 */
	public function testARenderRefusedByTheVendorIsRecordedAsFailed(): void {
		$provider = $this->providerDouble();
		$provider->method('render')->willThrowException(new DocumentGenerationException(message: 'Template unknown'));

		$job = $this->serviceWith($provider)->requestRender(sourceId: 'source-1', templateId: 't', data: [], requestedBy: 'ambtenaar')->getObject();

		$this->assertSame('failed', $job['status']);
		$this->assertSame('Template unknown', $job['lastError']);
		$this->assertSame('', $job['unreachableSince']);
		$this->assertCount(1, $this->dispatched);
		$this->assertSame('failed', $this->dispatched[0]->getStatus());
		$this->assertSame('Template unknown', $this->dispatched[0]->getError());

	}//end testARenderRefusedByTheVendorIsRecordedAsFailed()

	/**
	 * An unexpected throw is logged and recorded as unreachable, never as a refusal.
	 *
	 * @return void
	 */
	public function testAnUnexpectedThrowIsLoggedAndRecordedAsUnreachable(): void {
		$provider = $this->providerDouble();
		$provider->method('render')->willThrowException(new RuntimeException('boom'));

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'[DocumentGenerationService] unexpected failure calling smartdocuments',
				['exception' => 'boom']
			);

		$job = $this->serviceWith($provider)->requestRender(sourceId: 'source-1', templateId: 't', data: [])->getObject();

		$this->assertSame('unreachable', $job['status']);
		$this->assertSame('', $job['lastError']);
		$this->assertNotSame('', $job['unreachableSince']);
		$this->assertSame('The render failed unexpectedly: boom', $job['attempts'][0]['detail']);
		$this->assertSame([], $this->dispatched, 'an unreachable render is not terminal');

	}//end testAnUnexpectedThrowIsLoggedAndRecordedAsUnreachable()

	/**
	 * A rendered document the vendor refuses to hand over fails the job, keeping the job id.
	 *
	 * @return void
	 */
	public function testARenderedDocumentThatCannotBeFetchedFailsTheJob(): void {
		$provider = $this->providerDouble();
		$provider->method('render')->willReturn(RenderOutcome::rendered(providerJobId: 'v-1', fileReference: 'doc-1'));
		$provider->expects($this->once())
			->method('fetch')
			->with(self::SOURCE['configuration'], 'doc-1')
			->willThrowException(new DocumentGenerationException(message: 'HTTP 403'));

		$job = $this->serviceWith($provider)->requestRender(sourceId: 'source-1', templateId: 't', data: [])->getObject();

		$this->assertSame('failed', $job['status']);
		$this->assertSame('v-1', $job['providerJobId']);
		$this->assertSame('The rendered document could not be fetched: HTTP 403', $job['lastError']);
		$this->assertSame('', $job['fileReference']);
		$this->assertArrayNotHasKey('documentBytes', $job);

	}//end testARenderedDocumentThatCannotBeFetchedFailsTheJob()

	/**
	 * A poll whose source is gone records the job as unreachable without asking a vendor.
	 *
	 * @return void
	 */
	public function testAPollWhoseSourceIsGoneIsRecordedAsUnreachable(): void {
		$this->source = null;
		$provider = $this->providerDouble();
		$provider->expects($this->never())->method('status');

		$job = ObjectServiceMockBuilder::objectEntity(
			$this,
			['sourceId' => 'source-9', 'status' => 'queued', 'providerJobId' => 'v-2', 'attempts' => []],
			'job-2'
		);

		$polled = $this->serviceWith($provider)->pollJob(job: $job)->getObject();

		$this->assertSame('unreachable', $polled['status']);
		$this->assertSame('v-2', $polled['providerJobId']);
		$this->assertSame('No document generation source "source-9" on this instance.', $polled['attempts'][0]['detail']);
		$this->assertSame([], $this->dispatched);

	}//end testAPollWhoseSourceIsGoneIsRecordedAsUnreachable()

	/**
	 * A render request naming no source is refused before anything is saved.
	 *
	 * @return void
	 */
	public function testARenderNamingNoSourceIsRefused(): void {
		$this->objectService->expects($this->never())->method('find');

		try {
			$this->serviceWith($this->providerDouble())->requestRender(sourceId: '', templateId: 't', data: []);
			$this->fail('expected a refusal');
		} catch (DocumentGenerationException $exception) {
			$this->assertSame('A render names no document generation source.', $exception->getMessage());
		}

		$this->assertSame([], $this->saved);

	}//end testARenderNamingNoSourceIsRefused()

	/**
	 * A render request naming a source of another type is refused.
	 *
	 * @return void
	 */
	public function testARenderNamingASourceOfAnotherTypeIsRefused(): void {
		$this->source = ObjectServiceMockBuilder::objectEntity($this, ['type' => 'api', 'configuration' => []], 'source-1');

		$this->expectException(DocumentGenerationException::class);
		$this->expectExceptionMessage('Source "source-1" is not a document generation source.');

		$this->serviceWith($this->providerDouble())->requestRender(sourceId: 'source-1', templateId: 't', data: []);

	}//end testARenderNamingASourceOfAnotherTypeIsRefused()
}//end class
