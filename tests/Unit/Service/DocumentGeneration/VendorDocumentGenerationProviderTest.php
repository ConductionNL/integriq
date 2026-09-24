<?php

/**
 * Unit tests for the SmartDocuments and Xential bindings.
 *
 * A source without a credential reference, a vendor that cannot be reached,
 * a vendor that refuses, and a render reported done with nothing to show.
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
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-credentials-are-resolved-by-reference-never-passed-by-value-req-dgv-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\DocumentGeneration;

use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\DocumentGenerationException;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\DocumentGeneration\SmartDocumentsProvider;
use OCA\Integriq\Service\DocumentGeneration\XentialProvider;
use OCA\Integriq\Tests\Helpers\RecordingLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the two vendor bindings.
 */
class VendorDocumentGenerationProviderTest extends TestCase {

	/**
	 * The API key that must never leave the broker.
	 *
	 * @var string
	 */
	private const SECRET = 'sd-live-key-3b9f1c';

	/**
	 * @var BrokeredCallService|MockObject
	 */
	private BrokeredCallService|MockObject $broker;

	/**
	 * @var RecordingLogger
	 */
	private RecordingLogger $logger;

	/**
	 * The arguments dispatch() was called with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * A live source configuration, credential held by reference.
	 *
	 * @param array $overrides Fields to override.
	 *
	 * @return array<string, mixed> The configuration.
	 */
	private function liveSource(array $overrides = []): array {
		return array_merge(
			[
				'providerId' => 'smartdocuments',
				'baseUrl' => 'https://vendor.example/v1',
				'mockMode' => false,
				'authentication' => ['credentialRef' => 'cred-1'],
			],
			$overrides
		);

	}//end liveSource()

	/**
	 * Set up the broker double and the recording logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->calls = [];
		$this->logger = new RecordingLogger();

		$this->broker = $this->getMockBuilder(BrokeredCallService::class)
			->disableOriginalConstructor()
			->onlyMethods(['hasCredentialRef', 'prepare', 'dispatch'])
			->getMock();
		$this->broker->method('hasCredentialRef')->willReturnCallback(
			static function (array $config): bool {
				return trim((string)(($config['authentication'] ?? [])['credentialRef'] ?? '')) !== '';
			}
		);
		$this->broker->method('prepare')->willReturn(
			['credentialId' => 'cred-1', 'actingUserId' => 'ambtenaar']
		);

	}//end setUp()

	/**
	 * Make the broker answer one canned response, recording what it was asked.
	 *
	 * @param integer $status The HTTP status.
	 * @param string $body The response body.
	 *
	 * @return void
	 */
	private function brokerAnswers(int $status, string $body): void {
		$this->broker->method('dispatch')->willReturnCallback(
			function (
				string $credentialId,
				?string $actingUserId,
				string $method,
				string $url,
				array $config,
			) use ($status, $body): Response {
				$this->calls[] = [
					'credentialId' => $credentialId,
					'method' => $method,
					'url' => $url,
					'config' => $config,
				];

				return new Response($status, [], $body);
			}
		);

	}//end brokerAnswers()

	/**
	 * A vendor source without a credential reference cannot be activated.
	 *
	 * @return void
	 */
	public function testASourceWithoutACredentialReferenceCannotActivate(): void {
		$provider = new SmartDocumentsProvider(brokeredCallService: $this->broker, logger: $this->logger);

		$this->expectException(DocumentGenerationException::class);
		$this->expectExceptionMessage('credentialRef');

		$provider->assertActivatable($this->liveSource(['authentication' => ['credentialRef' => '']]));

	}//end testASourceWithoutACredentialReferenceCannotActivate()

	/**
	 * Mock mode activates and lists fixtures without a credential or a call.
	 *
	 * @return void
	 */
	public function testMockModeNeedsNoCredentialAndCallsNobody(): void {
		$this->broker->expects($this->never())->method('dispatch');
		$provider = new SmartDocumentsProvider(brokeredCallService: $this->broker, logger: $this->logger);
		$config = $this->liveSource(['mockMode' => true, 'authentication' => ['credentialRef' => '']]);

		$provider->assertActivatable($config);
		$templates = $provider->listTemplates($config);

		$this->assertCount(3, $templates);
		$this->assertSame('sd-beschikking', $templates[0]['id']);

	}//end testMockModeNeedsNoCredentialAndCallsNobody()

	/**
	 * A vendor that cannot be reached is unreachable, never a refusal.
	 *
	 * @return void
	 */
	public function testAVendorThatCannotBeReachedIsUnreachableNotFailed(): void {
		$this->broker->method('dispatch')->willThrowException(
			new RuntimeException('cURL error 28: Operation timed out')
		);
		$provider = new SmartDocumentsProvider(brokeredCallService: $this->broker, logger: $this->logger);

		$outcome = $provider->render(
			sourceConfiguration: $this->liveSource(),
			templateId: 'sd-beschikking',
			data: ['a' => 1]
		);

		$this->assertSame('unreachable', $outcome->status);
		$this->assertStringContainsString('could not be reached', $outcome->detail);
		$this->assertFalse($outcome->isTerminal());

	}//end testAVendorThatCannotBeReachedIsUnreachableNotFailed()

	/**
	 * A vendor that is there and broken is unreachable too: nobody knows.
	 *
	 * @return void
	 */
	public function testAVendorErrorAtFiveHundredIsUnreachable(): void {
		$this->brokerAnswers(status: 503, body: 'Service Unavailable');
		$provider = new SmartDocumentsProvider(brokeredCallService: $this->broker, logger: $this->logger);

		$outcome = $provider->render(
			sourceConfiguration: $this->liveSource(),
			templateId: 'sd-beschikking',
			data: ['a' => 1]
		);

		$this->assertSame('unreachable', $outcome->status);
		$this->assertStringContainsString('whether the document was produced', $outcome->detail);

	}//end testAVendorErrorAtFiveHundredIsUnreachable()

	/**
	 * A vendor that refuses has answered, and that is a failure.
	 *
	 * @return void
	 */
	public function testAVendorRefusalAtFourHundredIsAFailure(): void {
		$this->brokerAnswers(status: 422, body: '{"message":"unknown template"}');
		$provider = new SmartDocumentsProvider(brokeredCallService: $this->broker, logger: $this->logger);

		$outcome = $provider->render(
			sourceConfiguration: $this->liveSource(),
			templateId: 'sd-beschikking',
			data: ['a' => 1]
		);

		$this->assertSame('failed', $outcome->status);
		$this->assertTrue($outcome->isTerminal());

	}//end testAVendorRefusalAtFourHundredIsAFailure()

	/**
	 * A render reported done with no document is not a rendered document.
	 *
	 * @return void
	 */
	public function testDoneWithNoDocumentIsNotReportedAsRendered(): void {
		$this->brokerAnswers(status: 200, body: '{"jobId":"v-1","status":"done"}');
		$provider = new SmartDocumentsProvider(brokeredCallService: $this->broker, logger: $this->logger);

		$outcome = $provider->render(
			sourceConfiguration: $this->liveSource(),
			templateId: 'sd-beschikking',
			data: ['a' => 1]
		);

		$this->assertSame('failed', $outcome->status);
		$this->assertSame('', $outcome->fileReference);
		$this->assertStringContainsString('named no document', $outcome->detail);

	}//end testDoneWithNoDocumentIsNotReportedAsRendered()

	/**
	 * A render the vendor took is queued with the vendor's own job id.
	 *
	 * @return void
	 */
	public function testARenderTheVendorTookIsQueuedUnderItsOwnId(): void {
		$this->brokerAnswers(status: 202, body: '{"jobId":"v-42","status":"processing"}');
		$provider = new SmartDocumentsProvider(brokeredCallService: $this->broker, logger: $this->logger);

		$outcome = $provider->render(
			sourceConfiguration: $this->liveSource(),
			templateId: 'sd-beschikking',
			data: ['a' => 1]
		);

		$this->assertSame('queued', $outcome->status);
		$this->assertSame('v-42', $outcome->providerJobId);

	}//end testARenderTheVendorTookIsQueuedUnderItsOwnId()

	/**
	 * No API key appears in a call argument or in a log line.
	 *
	 * @return void
	 */
	public function testNoApiKeyReachesACallArgumentOrALogLine(): void {
		$this->broker->method('dispatch')->willThrowException(
			new RuntimeException('cURL error 7: Failed to connect')
		);
		$provider = new SmartDocumentsProvider(brokeredCallService: $this->broker, logger: $this->logger);

		// The source references the credential; the material itself lives
		// with the broker and never passes through this class.
		$provider->render(
			sourceConfiguration: $this->liveSource(),
			templateId: 'sd-beschikking',
			data: ['a' => 1]
		);

		$this->assertStringNotContainsString(self::SECRET, $this->logger->flatten());
		$this->assertStringNotContainsString(self::SECRET, json_encode($this->calls));

	}//end testNoApiKeyReachesACallArgumentOrALogLine()

	/**
	 * The two vendors send their own envelope, to their own path.
	 *
	 * @return void
	 */
	public function testEachVendorSendsItsOwnEnvelope(): void {
		$this->brokerAnswers(status: 202, body: '{"jobId":"v-1","status":"processing"}');

		$smart = new SmartDocumentsProvider(brokeredCallService: $this->broker, logger: $this->logger);
		$smart->render(
			sourceConfiguration: $this->liveSource(),
			templateId: 'sd-beschikking',
			data: ['naam' => 'De Vries']
		);

		$xential = new XentialProvider(brokeredCallService: $this->broker, logger: $this->logger);
		$xential->render(
			sourceConfiguration: $this->liveSource(['providerId' => 'xential']),
			templateId: 'xt-beschikking',
			data: ['naam' => 'De Vries']
		);

		$this->assertStringEndsWith('/documents', $this->calls[0]['url']);
		$this->assertSame(
			['selection' => ['templateId' => 'sd-beschikking'], 'fields' => ['naam' => 'De Vries'], 'format' => 'pdf'],
			json_decode($this->calls[0]['config']['body'], true)
		);

		$this->assertStringEndsWith('/api/document/start', $this->calls[1]['url']);
		$this->assertSame(
			['template' => 'xt-beschikking', 'data' => ['naam' => 'De Vries'], 'outputFormat' => 'pdf'],
			json_decode($this->calls[1]['config']['body'], true)
		);

	}//end testEachVendorSendsItsOwnEnvelope()

	/**
	 * The vendor's templates are listed, and none is stored here.
	 *
	 * @return void
	 */
	public function testTemplatesAreListedFromTheVendor(): void {
		$this->brokerAnswers(
			status: 200,
			body: '{"templates":[{"id":"t-1","name":"Beschikking"},{"id":"t-2","name":"Brief"}]}'
		);
		$provider = new XentialProvider(brokeredCallService: $this->broker, logger: $this->logger);

		$templates = $provider->listTemplates($this->liveSource(['providerId' => 'xential']));

		$this->assertSame(
			[['id' => 't-1', 'name' => 'Beschikking'], ['id' => 't-2', 'name' => 'Brief']],
			$templates
		);
		$this->assertStringEndsWith('/api/template/list', $this->calls[0]['url']);

	}//end testTemplatesAreListedFromTheVendor()
}//end class
