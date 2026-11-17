<?php

/**
 * The dry-run preview of a webhook redelivery.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\JobService;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Service\Security\SensitiveValueMasker;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\Integriq\Tests\Support\LegacyObjectArgs;

/**
 * EventService::previewWebhookDelivery() resolves the request a webhook
 * redelivery would send, redacted, and never dispatches it.
 */
#[CoversClass(EventService::class)]
#[UsesClass(SensitiveFieldRegistry::class)]
#[UsesClass(SensitiveValueMasker::class)]
#[UsesClass(WebhookSignatureService::class)]
class EventServicePreviewWebhookDeliveryTest extends TestCase {

	/**
	 * @var MockObject The OpenRegister object service double.
	 */
	private MockObject $objectService;

	/**
	 * @var MockObject The HTTP client factory, which must never be asked for a client.
	 */
	private MockObject $clientService;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->clientService = $this->createMock(IClientService::class);
		$this->clientService->expects($this->never())->method('newClient');

	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return EventService
	 */
	private function service(): EventService {
		$logger = $this->createMock(LoggerInterface::class);

		return new EventService(
			$this->objectService,
			$this->clientService,
			$logger,
			new WebhookSignatureService($logger),
			$this->createMock(SynchronizationService::class),
			$this->createMock(JobService::class),
			$this->createMock(CallService::class),
			$this->createMock(FlowRunnerService::class),
		);

	}//end service()

	/**
	 * A message without a subscription previews an empty sink with the CloudEvents content type only.
	 *
	 * @return void
	 */
	public function testAMessageWithoutASubscriptionPreviewsNoSink(): void {
		$this->objectService->expects($this->never())->method('find');
		$message = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'failed'], 'msg-1');

		$preview = $this->service()->previewWebhookDelivery($message);

		$this->assertSame(
			['url' => '', 'method' => 'POST', 'headers' => ['Content-Type' => 'application/cloudevents+json']],
			$preview
		);

	}//end testAMessageWithoutASubscriptionPreviewsNoSink()

	/**
	 * A subscription that no longer exists previews the same defaults.
	 *
	 * @return void
	 */
	public function testAMissingSubscriptionPreviewsNoSink(): void {
		$this->objectService->expects($this->once())->method('find')
			->with(...LegacyObjectArgs::with(method: 'find', expected: [
				'id' => 'sub-gone',
				'register' => 'integriq',
				'schema' => 'event_subscription',
			]))
			->willReturn(null);
		$message = ObjectServiceMockBuilder::objectEntity($this, ['subscription' => 'sub-gone'], 'msg-2');

		$preview = $this->service()->previewWebhookDelivery($message);

		$this->assertSame('', $preview['url']);
		$this->assertSame(['Content-Type' => 'application/cloudevents+json'], $preview['headers']);

	}//end testAMissingSubscriptionPreviewsNoSink()

	/**
	 * The subscription's sink and headers are previewed; a signing secret adds the signature and event id headers.
	 *
	 * @return void
	 */
	public function testASigningSubscriptionPreviewsTheSignatureRedacted(): void {
		$subscription = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'sink' => 'https://receiver.example/hook',
				'protocolSettings' => [
					'headers' => ['X-Tenant' => 'zuid', 'Authorization' => 'Bearer live-token-123'],
					'signingSecret' => 'shh',
				],
			],
			'sub-1'
		);
		$this->objectService->method('find')->with(...LegacyObjectArgs::with(method: 'find', expected: [
				'id' => 'sub-1',
				'register' => 'integriq',
				'schema' => 'event_subscription',
			]))->willReturn($subscription);
		$message = ObjectServiceMockBuilder::objectEntity($this, ['subscription' => 'sub-1'], 'msg-3');

		$preview = $this->service()->previewWebhookDelivery($message);

		$this->assertSame('https://receiver.example/hook', $preview['url']);
		$this->assertSame('POST', $preview['method']);
		$headers = $preview['headers'];
		$this->assertSame('application/cloudevents+json', $headers['Content-Type']);
		$this->assertSame('zuid', $headers['X-Tenant']);
		$this->assertSame(SensitiveFieldRegistry::PLACEHOLDER, $headers['X-OpenConnector-Signature']);
		$this->assertSame('msg-3', $headers['X-OpenConnector-Event-Id']);
		$this->assertArrayHasKey('Authorization', $headers);
		$this->assertStringNotContainsString('live-token-123', (string)$headers['Authorization']);
		$this->assertSame(['Content-Type', 'X-Tenant', 'Authorization', 'X-OpenConnector-Signature', 'X-OpenConnector-Event-Id'], array_keys($headers));

	}//end testASigningSubscriptionPreviewsTheSignatureRedacted()

	/**
	 * An empty signing secret adds no signature headers.
	 *
	 * @return void
	 */
	public function testAnEmptySigningSecretAddsNoSignature(): void {
		$subscription = ObjectServiceMockBuilder::objectEntity(
			$this,
			['sink' => 'https://receiver.example/plain', 'protocolSettings' => ['signingSecret' => '']],
			'sub-2'
		);
		$this->objectService->method('find')->willReturn($subscription);
		$message = ObjectServiceMockBuilder::objectEntity($this, ['subscription' => 'sub-2'], 'msg-4');

		$preview = $this->service()->previewWebhookDelivery($message);

		$this->assertSame(
			['url' => 'https://receiver.example/plain', 'method' => 'POST', 'headers' => ['Content-Type' => 'application/cloudevents+json']],
			$preview
		);

	}//end testAnEmptySigningSecretAddsNoSignature()
}//end class
