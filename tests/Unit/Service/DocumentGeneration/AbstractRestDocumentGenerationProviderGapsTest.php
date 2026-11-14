<?php

/**
 * Unit tests for the shared REST vendor binding, through the Xential binding.
 *
 * The configuration schema, mock mode for render, status and fetch, a live
 * template listing, a source without a base URL, a vendor that refuses a
 * status poll and a vendor that reports the render as failed.
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

use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\DocumentGenerationException;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\DocumentGeneration\AbstractRestDocumentGenerationProvider;
use OCA\Integriq\Service\DocumentGeneration\RenderOutcome;
use OCA\Integriq\Service\DocumentGeneration\VendorCall;
use OCA\Integriq\Service\DocumentGeneration\XentialProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the parts of the shared REST binding the vendor tests leave out.
 */
#[CoversClass(AbstractRestDocumentGenerationProvider::class)]
#[UsesClass(XentialProvider::class)]
#[UsesClass(RenderOutcome::class)]
#[UsesClass(VendorCall::class)]
#[UsesClass(DocumentGenerationException::class)]
class AbstractRestDocumentGenerationProviderGapsTest extends TestCase {

	/**
	 * @var BrokeredCallService|MockObject
	 */
	private BrokeredCallService|MockObject $broker;

	/**
	 * The binding under test.
	 *
	 * @var XentialProvider
	 */
	private XentialProvider $provider;

	/**
	 * The calls the broker was asked to dispatch.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Set up the broker double and the binding.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->calls = [];
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

		$this->provider = new XentialProvider(
			brokeredCallService: $this->broker,
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * A live source configuration.
	 *
	 * @param array $overrides Fields to override.
	 *
	 * @return array<string, mixed> The configuration.
	 */
	private function liveSource(array $overrides = []): array {
		return array_merge(
			[
				'providerId' => 'xential',
				'baseUrl' => 'https://vendor.example/v1/',
				'mockMode' => false,
				'authentication' => ['credentialRef' => 'cred-1'],
			],
			$overrides
		);

	}//end liveSource()

	/**
	 * A mock-mode source configuration.
	 *
	 * @return array<string, mixed> The configuration.
	 */
	private function mockSource(): array {
		return ['providerId' => 'xential', 'mockMode' => true];

	}//end mockSource()

	/**
	 * Make the broker answer one canned response, recording each call.
	 *
	 * @param integer $status The HTTP status.
	 * @param string $body The body.
	 *
	 * @return void
	 */
	private function brokerAnswers(int $status, string $body): void {
		$this->broker->method('dispatch')->willReturnCallback(
			function (string $credentialId, ?string $actingUserId, string $method, string $url, array $config) use ($status, $body): Response {
				$this->calls[] = ['method' => $method, 'url' => $url];
				return new Response($status, [], $body);
			}
		);

	}//end brokerAnswers()

	/**
	 * The schema pins the provider id and offers the base URL, mock mode and credential reference.
	 *
	 * @return void
	 */
	public function testTheConfigSchemaPinsTheProviderIdAndNamesTheFields(): void {
		$schema = $this->provider->getConfigSchema();

		$this->assertSame('object', $schema['type']);
		$this->assertSame(['providerId'], $schema['required']);
		$this->assertSame(['type' => 'string', 'const' => 'xential'], $schema['properties']['providerId']);
		$this->assertSame(['providerId', 'baseUrl', 'mockMode', 'authentication'], array_keys($schema['properties']));
		$this->assertSame('boolean', $schema['properties']['mockMode']['type']);
		$this->assertSame(
			'string',
			$schema['properties']['authentication']['properties']['credentialRef']['type']
		);

	}//end testTheConfigSchemaPinsTheProviderIdAndNamesTheFields()

	/**
	 * A source with a credential but no base URL cannot be activated.
	 *
	 * @return void
	 */
	public function testASourceWithoutABaseUrlIsNotActivatable(): void {
		$this->expectException(DocumentGenerationException::class);
		$this->expectExceptionMessage('The xential binding needs `configuration.baseUrl`, and this source carries none.');

		$this->provider->assertActivatable(sourceConfiguration: $this->liveSource(['baseUrl' => '   ']));

	}//end testASourceWithoutABaseUrlIsNotActivatable()

	/**
	 * A live listing keeps templates with an id, names them, and skips the rest.
	 *
	 * @return void
	 */
	public function testALiveListingKeepsIdentifiedTemplatesAndNamesThem(): void {
		$this->brokerAnswers(
			200,
			json_encode(
				[
					'data' => [
						['templateId' => 't-1', 'name' => 'Beschikking'],
						['id' => 't-2'],
						['name' => 'No id at all'],
					],
				]
			)
		);

		$templates = $this->provider->listTemplates(sourceConfiguration: $this->liveSource());

		$this->assertSame(
			[
				['id' => 't-1', 'name' => 'Beschikking'],
				['id' => 't-2', 'name' => 't-2'],
			],
			$templates
		);
		$this->assertSame(
			[['method' => 'GET', 'url' => 'https://vendor.example/v1/api/template/list']],
			$this->calls
		);

	}//end testALiveListingKeepsIdentifiedTemplatesAndNamesThem()

	/**
	 * In mock mode a render is queued under a stable mock job id and the vendor is not called.
	 *
	 * @return void
	 */
	public function testAMockRenderIsQueuedUnderAStableJobIdWithoutCallingTheVendor(): void {
		$this->broker->expects($this->never())->method('dispatch');

		$data = ['naam' => 'Jansen'];
		$outcome = $this->provider->render(sourceConfiguration: $this->mockSource(), templateId: 'xt-brief', data: $data);

		$expectedId = 'MOCK-XENTIAL-' . substr(hash('sha256', 'xt-brief' . json_encode($data)), 0, 12);
		$this->assertSame('queued', $outcome->status);
		$this->assertSame($expectedId, $outcome->providerJobId);
		$this->assertSame('Mock mode: the vendor was not called', $outcome->detail);

		$again = $this->provider->render(sourceConfiguration: $this->mockSource(), templateId: 'xt-brief', data: $data);
		$this->assertSame($expectedId, $again->providerJobId);

	}//end testAMockRenderIsQueuedUnderAStableJobIdWithoutCallingTheVendor()

	/**
	 * In mock mode a status poll reports a fixture document.
	 *
	 * @return void
	 */
	public function testAMockStatusReportsAFixtureDocument(): void {
		$this->broker->expects($this->never())->method('dispatch');

		$outcome = $this->provider->status(sourceConfiguration: $this->mockSource(), providerJobId: 'job-7');

		$this->assertSame('rendered', $outcome->status);
		$this->assertSame('job-7', $outcome->providerJobId);
		$this->assertSame('mock:job-7', $outcome->fileReference);
		$this->assertSame('Mock mode: a fixture document', $outcome->detail);

	}//end testAMockStatusReportsAFixtureDocument()

	/**
	 * In mock mode a fetch returns a fixture PDF naming the reference.
	 *
	 * @return void
	 */
	public function testAMockFetchReturnsAFixturePdf(): void {
		$this->broker->expects($this->never())->method('dispatch');

		$this->assertSame(
			"%PDF-1.4 fixture\nReference: doc-9\n",
			$this->provider->fetch(sourceConfiguration: $this->mockSource(), fileReference: 'doc-9')
		);

	}//end testAMockFetchReturnsAFixturePdf()

	/**
	 * A status poll the vendor refuses (4xx) is a failure, not unreachable, and keeps the job id.
	 *
	 * @return void
	 */
	public function testARefusedStatusPollFailsAndKeepsTheJobId(): void {
		$this->brokerAnswers(404, '{}');

		$outcome = $this->provider->status(sourceConfiguration: $this->liveSource(), providerJobId: 'job 8');

		$this->assertSame('failed', $outcome->status);
		$this->assertSame('job 8', $outcome->providerJobId);
		$this->assertSame('The xential vendor refused the request with HTTP 404.', $outcome->detail);
		$this->assertSame('https://vendor.example/v1/api/document/job%208/status', $this->calls[0]['url']);

	}//end testARefusedStatusPollFailsAndKeepsTheJobId()

	/**
	 * A vendor that reports the render as failed yields a failed outcome with its message.
	 *
	 * @return void
	 */
	public function testAVendorReportedFailureCarriesTheVendorMessage(): void {
		$this->brokerAnswers(200, json_encode(['jobId' => 'job-3', 'status' => 'Rejected', 'message' => 'Template missing']));

		$outcome = $this->provider->status(sourceConfiguration: $this->liveSource(), providerJobId: 'job-3');

		$this->assertSame('failed', $outcome->status);
		$this->assertSame('job-3', $outcome->providerJobId);
		$this->assertSame('Template missing', $outcome->detail);

	}//end testAVendorReportedFailureCarriesTheVendorMessage()

	/**
	 * A vendor failure without a message gets the default refusal detail.
	 *
	 * @return void
	 */
	public function testAVendorFailureWithoutAMessageGetsTheDefaultDetail(): void {
		$this->brokerAnswers(200, json_encode(['id' => 'job-4', 'status' => 'error']));

		$outcome = $this->provider->status(sourceConfiguration: $this->liveSource(), providerJobId: 'ignored');

		$this->assertSame('failed', $outcome->status);
		$this->assertSame('job-4', $outcome->providerJobId);
		$this->assertSame('The vendor refused this render', $outcome->detail);

	}//end testAVendorFailureWithoutAMessageGetsTheDefaultDetail()
}//end class
