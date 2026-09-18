<?php

/**
 * Unit tests for the document generation provider registry and the log binding.
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
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-one-provider-seam-with-log-smartdocuments-and-xential-bindings-req-dgv-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\DocumentGeneration;

use OCA\Integriq\Exception\DocumentGenerationException;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationProviderRegistry;
use OCA\Integriq\Service\DocumentGeneration\LogDocumentGenerationProvider;
use OCA\Integriq\Service\DocumentGeneration\SmartDocumentsProvider;
use OCA\Integriq\Service\DocumentGeneration\XentialProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for provider resolution and the sandbox binding.
 */
class DocumentGenerationProviderRegistryTest extends TestCase {

	/**
	 * @var DocumentGenerationProviderRegistry
	 */
	private DocumentGenerationProviderRegistry $registry;

	/**
	 * @var LogDocumentGenerationProvider
	 */
	private LogDocumentGenerationProvider $logProvider;

	/**
	 * Build the registry over the three shipped bindings.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$broker = $this->getMockBuilder(BrokeredCallService::class)
			->disableOriginalConstructor()
			->onlyMethods(['hasCredentialRef', 'prepare', 'dispatch'])
			->getMock();

		$this->logProvider = new LogDocumentGenerationProvider();
		$this->registry = new DocumentGenerationProviderRegistry(
			logProvider: $this->logProvider,
			smartDocumentsProvider: new SmartDocumentsProvider(
				brokeredCallService: $broker,
				logger: new NullLogger()
			),
			xentialProvider: new XentialProvider(
				brokeredCallService: $broker,
				logger: new NullLogger()
			)
		);

	}//end setUp()

	/**
	 * Every shipped binding is resolvable by the id it answers to.
	 *
	 * @return void
	 */
	public function testEveryShippedBindingResolvesByItsOwnId(): void {
		foreach (['log', 'smartdocuments', 'xential'] as $providerId) {
			$provider = $this->registry->resolve(['providerId' => $providerId]);

			$this->assertSame($providerId, $provider->getProviderId());
		}

	}//end testEveryShippedBindingResolvesByItsOwnId()

	/**
	 * A binding nobody ships is refused, and the refusal lists what there is.
	 *
	 * @return void
	 */
	public function testAnUnknownBindingIsRefusedWithTheListOfRealOnes(): void {
		$this->expectException(DocumentGenerationException::class);
		$this->expectExceptionMessage('log, smartdocuments, xential');

		$this->registry->resolve(['providerId' => 'smartdocs']);

	}//end testAnUnknownBindingIsRefusedWithTheListOfRealOnes()

	/**
	 * A source naming no binding is refused rather than defaulted.
	 *
	 * @return void
	 */
	public function testASourceNamingNoBindingIsRefusedRatherThanDefaulted(): void {
		$this->expectException(DocumentGenerationException::class);
		$this->expectExceptionMessage('names no providerId');

		$this->registry->resolve([]);

	}//end testASourceNamingNoBindingIsRefusedRatherThanDefaulted()

	/**
	 * The log binding renders a placeholder that names the template and the data hash.
	 *
	 * @return void
	 */
	public function testTheLogBindingRendersAPlaceholderNamingTheTemplateAndTheHash(): void {
		$data = ['naam' => 'De Vries'];
		$outcome = $this->logProvider->render(
			sourceConfiguration: [],
			templateId: 'mock-beschikking',
			data: $data
		);

		$this->assertSame('rendered', $outcome->status);

		$document = $this->logProvider->fetch(sourceConfiguration: [], fileReference: $outcome->fileReference);

		$this->assertStringContainsString('mock-beschikking', $document);
		$this->assertStringContainsString(hash('sha256', json_encode($data)), $document);
		$this->assertStringContainsString('not by a vendor', $document);

	}//end testTheLogBindingRendersAPlaceholderNamingTheTemplateAndTheHash()

	/**
	 * The log binding says it has no such render rather than pretending.
	 *
	 * @return void
	 */
	public function testTheLogBindingRefusesADocumentItNeverMade(): void {
		$this->expectException(DocumentGenerationException::class);

		$this->logProvider->fetch(sourceConfiguration: [], fileReference: 'log:never-made');

	}//end testTheLogBindingRefusesADocumentItNeverMade()
}//end class
