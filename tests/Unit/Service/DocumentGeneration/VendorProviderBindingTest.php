<?php

/**
 * Unit tests for what each vendor binding names itself and where it asks for a render's status.
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
 * @spec openspec/specs/document-generation-vendor-adapter/spec.md#requirement-one-provider-seam-with-log-smartdocuments-and-xential-bindings-req-dgv-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\DocumentGeneration;

use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\DocumentGeneration\AbstractRestDocumentGenerationProvider;
use OCA\Integriq\Service\DocumentGeneration\RenderOutcome;
use OCA\Integriq\Service\DocumentGeneration\SmartDocumentsProvider;
use OCA\Integriq\Service\DocumentGeneration\VendorCall;
use OCA\Integriq\Service\DocumentGeneration\XentialProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The provider id and display name each binding registers under, and the
 * status call each makes for a job the vendor is working on.
 */
#[CoversClass(XentialProvider::class)]
#[CoversClass(SmartDocumentsProvider::class)]
#[UsesClass(AbstractRestDocumentGenerationProvider::class)]
#[UsesClass(RenderOutcome::class)]
#[UsesClass(VendorCall::class)]
class VendorProviderBindingTest extends TestCase {

	/**
	 * The broker double.
	 *
	 * @var BrokeredCallService|MockObject
	 */
	private BrokeredCallService|MockObject $broker;

	/**
	 * The method and URL of each dispatched call.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $calls = [];

	/**
	 * Set up a broker that answers a finished render.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->broker = $this->getMockBuilder(BrokeredCallService::class)
			->disableOriginalConstructor()
			->onlyMethods(['hasCredentialRef', 'prepare', 'dispatch'])
			->getMock();
		$this->broker->method('hasCredentialRef')->willReturn(true);
		$this->broker->method('prepare')->willReturn(['credentialId' => 'cred-1', 'actingUserId' => 'ambtenaar']);
		$this->broker->method('dispatch')->willReturnCallback(
			function (string $credentialId, ?string $actingUserId, string $method, string $url): Response {
				$this->calls[] = [$method, $url];
				return new Response(200, [], '{"jobId":"job/7 a","status":"completed","documentId":"doc-99"}');
			}
		);

	}//end setUp()

	/**
	 * A live source.
	 *
	 * @param string $providerId The provider.
	 *
	 * @return array<string, mixed>
	 */
	private static function source(string $providerId): array {
		return [
			'providerId' => $providerId,
			'baseUrl' => 'https://vendor.example/v1',
			'mockMode' => false,
			'authentication' => ['credentialRef' => 'cred-1'],
		];

	}//end source()

	/**
	 * Each binding registers under its own id and shows its vendor's name.
	 *
	 * @return void
	 */
	public function testEachBindingNamesItsVendor(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$xential = new XentialProvider(brokeredCallService: $this->broker, logger: $logger);
		$smart = new SmartDocumentsProvider(brokeredCallService: $this->broker, logger: $logger);

		$this->assertSame(
			[['xential', 'Xential'], ['smartdocuments', 'SmartDocuments']],
			[[$xential->getProviderId(), $xential->getProviderName()], [$smart->getProviderId(), $smart->getProviderName()]]
		);

	}//end testEachBindingNamesItsVendor()

	/**
	 * Each binding asks for the status on its own path, with the job id encoded,
	 * and a completed job with a document is rendered.
	 *
	 * @return void
	 */
	public function testEachBindingAsksForTheStatusOnItsOwnPath(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$xential = (new XentialProvider(brokeredCallService: $this->broker, logger: $logger))->status(sourceConfiguration: self::source('xential'), providerJobId: 'job/7 a');
		$smart = (new SmartDocumentsProvider(brokeredCallService: $this->broker, logger: $logger))->status(sourceConfiguration: self::source('smartdocuments'), providerJobId: 'job/7 a');

		$this->assertSame(
			[
				['GET', 'https://vendor.example/v1/api/document/job%2F7%20a/status'],
				['GET', 'https://vendor.example/v1/documents/job%2F7%20a/status'],
			],
			$this->calls
		);
		foreach ([$xential, $smart] as $outcome) {
			$this->assertSame(['rendered', 'job/7 a', 'doc-99'], [$outcome->status, $outcome->providerJobId, $outcome->fileReference]);
		}

	}//end testEachBindingAsksForTheStatusOnItsOwnPath()
}//end class
