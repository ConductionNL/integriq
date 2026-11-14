<?php

/**
 * Failure paths of the document generation operator endpoints.
 *
 * What `templates` and `activate` answer when the source does not exist, the
 * vendor is unreachable or the store fails, and how an empty credential
 * reference is dropped while the rest of the authentication is kept. The
 * object service, the provider registry, the provider and the logger are
 * doubles.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\DocumentGenerationController;
use OCA\Integriq\Exception\DocumentGenerationException;
use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationProviderInterface;
use OCA\Integriq\Service\DocumentGeneration\DocumentProviderRegistryInterface;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Controller\DocumentGenerationController
 * @uses   \OCA\Integriq\Exception\DocumentGenerationException
 */
class DocumentGenerationControllerFailureTest extends TestCase {

	/**
	 * The objects saveObject() was called with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The provider.
	 *
	 * @var DocumentGenerationProviderInterface&MockObject
	 */
	private DocumentGenerationProviderInterface&MockObject $provider;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Reset the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved    = [];
		$this->provider = $this->createMock(DocumentGenerationProviderInterface::class);
		$this->provider->method('getProviderId')->willReturn('smartdocuments');
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The controller over one stored source, or none.
	 *
	 * @param array<string, mixed>|null $source    The source object; null when there is none.
	 * @param \Throwable|null           $saveFails What saveObject() throws.
	 *
	 * @return DocumentGenerationController
	 */
	private function controllerFor(?array $source, ?\Throwable $saveFails = null): DocumentGenerationController {
		$objectService = $this->getMockBuilder(ORObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'saveObject'])
			->getMock();
		$objectService->method('find')->willReturn(
			$source === null ? null : ObjectServiceMockBuilder::objectEntity($this, $source, 'source-1')
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) use ($saveFails) {
				if ($saveFails !== null) {
					throw $saveFails;
				}

				$this->saved[] = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'source-1');
			}
		);

		$registry = $this->createMock(DocumentProviderRegistryInterface::class);
		$registry->method('resolve')->willReturn($this->provider);

		return new DocumentGenerationController(
			appName: 'integriq',
			request: $this->createMock(IRequest::class),
			objectService: $objectService,
			registry: $registry,
			logger: $this->logger
		);

	}//end controllerFor()

	/**
	 * A stored document generation source.
	 *
	 * @param array<string, mixed> $configuration Its configuration.
	 *
	 * @return array<string, mixed>
	 */
	private static function source(array $configuration = ['provider' => 'smartdocuments']): array {
		return ['type' => 'documentGeneration', 'title' => 'Sjablonen', 'configuration' => $configuration];

	}//end source()

	public function testAMissingSourceIsNamedAndRefused(): void {
		$response = $this->controllerFor(null)->templates('nope');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'No document generation source "nope" on this instance.', 'unreachable' => false], $response->getData());

	}//end testAMissingSourceIsNamedAndRefused()

	public function testAnUnreachableVendorIsSaidSo(): void {
		$this->provider->method('listTemplates')->willThrowException(
			(new DocumentGenerationException(message: 'SmartDocuments did not answer'))->asUnreachable()
		);

		$response = $this->controllerFor(self::source())->templates('source-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'SmartDocuments did not answer', 'unreachable' => true], $response->getData());

	}//end testAnUnreachableVendorIsSaidSo()

	public function testAnyOtherTemplatesFailureIsA500WithoutTheCauseAndIsLogged(): void {
		$this->provider->method('listTemplates')->willThrowException(new RuntimeException('socket closed'));
		$this->logger->expects($this->once())->method('error')
			->with('[integriq] the vendor templates could not be listed: socket closed', $this->arrayHasKey('exception'));

		$response = $this->controllerFor(self::source())->templates('source-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'The vendor templates could not be listed.'], $response->getData());

	}//end testAnyOtherTemplatesFailureIsA500WithoutTheCauseAndIsLogged()

	public function testTheTemplatesAreListedWithTheProvider(): void {
		$this->provider->expects($this->once())->method('listTemplates')
			->with(['provider' => 'smartdocuments'])
			->willReturn([['id' => 't1', 'name' => 'Besluit']]);

		$this->assertSame(
			['providerId' => 'smartdocuments', 'templates' => [['id' => 't1', 'name' => 'Besluit']]],
			$this->controllerFor(self::source())->templates('source-1')->getData()
		);

	}//end testTheTemplatesAreListedWithTheProvider()

	public function testAFailingSaveOnActivateIsA500AndIsLogged(): void {
		$this->logger->expects($this->once())->method('error')
			->with('[integriq] a document generation source could not be activated: locked', $this->arrayHasKey('exception'));

		$response = $this->controllerFor(self::source(), new RuntimeException('locked'))->activate('source-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'The source could not be activated.'], $response->getData());

	}//end testAFailingSaveOnActivateIsA500AndIsLogged()

	public function testAnEmptyCredentialReferenceIsDroppedAndTheRestOfTheAuthenticationKept(): void {
		$configuration = ['provider' => 'smartdocuments', 'authentication' => ['type' => 'basic', 'credentialRef' => '']];

		$response = $this->controllerFor(self::source($configuration))->activate('source-1');

		$this->assertSame(['isEnabled' => true, 'providerId' => 'smartdocuments'], $response->getData());
		$this->assertSame(['type' => 'basic'], $this->saved[0]['configuration']['authentication']);
		$this->assertTrue($this->saved[0]['isEnabled']);

	}//end testAnEmptyCredentialReferenceIsDroppedAndTheRestOfTheAuthenticationKept()

	public function testANonDocumentSourceIsRefusedOnActivate(): void {
		$response = $this->controllerFor(['type' => 'rest', 'configuration' => []])->activate('source-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Source "source-1" is not a document generation source.'], $response->getData());
		$this->assertSame([], $this->saved);

	}//end testANonDocumentSourceIsRefusedOnActivate()
}//end class
