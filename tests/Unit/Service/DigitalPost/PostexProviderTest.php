<?php

/**
 * Integriq — the Postex digital post binding: what it sends, where, and what
 * a status poll answers.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\DigitalPost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\DigitalPost;

use OCA\Integriq\Gateway\GatewayDelivery;
use OCA\Integriq\Gateway\GatewayTransport;
use OCA\Integriq\Service\DigitalPost\DigitalPostResult;
use OCA\Integriq\Service\DigitalPost\PostexProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Service\DigitalPost\PostexProvider
 * @uses   \OCA\Integriq\Service\DigitalPost\DigitalPostResult
 * @uses   \OCA\Integriq\Gateway\GatewayDelivery
 *
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-one-provider-seam-with-log-berichtenbox-and-postex-bindings-req-dpa-001
 */
class PostexProviderTest extends TestCase {

	public function testItIsThePostexProvider(): void {
		$provider = new PostexProvider($this->createMock(GatewayTransport::class));

		$this->assertSame('postex', $provider->getProviderId());
	}//end testItIsThePostexProvider()

	public function testTheConfigSchemaRequiresACampaignAndNamesTheSource(): void {
		$schema = (new PostexProvider($this->createMock(GatewayTransport::class)))->getConfigSchema();

		$this->assertSame('object', $schema['type']);
		$this->assertSame('Postex', $schema['title']);
		$this->assertSame(['campaign'], $schema['required']);
		$this->assertSame(['source', 'campaign'], array_keys($schema['properties']));
	}//end testTheConfigSchemaRequiresACampaignAndNamesTheSource()

	public function testABlankCampaignIsRefusedAndAFilledOneActivates(): void {
		$provider = new PostexProvider($this->createMock(GatewayTransport::class));

		$this->assertCount(1, $provider->activationRefusals(['campaign' => '   ']));
		$this->assertSame([], $provider->activationRefusals(['campaign' => 'besluiten']));
	}//end testABlankCampaignIsRefusedAndAFilledOneActivates()

	public function testASendPostsTheMessageToTheConfiguredSource(): void {
		$transport = $this->createMock(GatewayTransport::class);
		$transport->expects($this->once())
			->method('send')
			->with(
				'postex',
				[
					'campaign' => 'besluiten',
					'recipient' => '999993653',
					'subject' => 'Uw aanvraag',
					'body' => '',
					'attachments' => [],
				],
				['source' => 'postex-test', 'endpoint' => '/messages', 'method' => 'POST']
			)
			->willReturn(GatewayDelivery::delivered('postex', 'px-7'));

		$result = (new PostexProvider($transport))->send(
			['recipient' => '999993653', 'subject' => 'Uw aanvraag'],
			['campaign' => 'besluiten', 'source' => 'postex-test']
		);

		$this->assertFalse($result->isRefused());
		$this->assertSame(DigitalPostResult::STATUS_SENT, $result->getStatus());
		$this->assertSame('px-7', $result->getProviderReference());
	}//end testASendPostsTheMessageToTheConfiguredSource()

	public function testARefusedSendIsARefusalWithItsReason(): void {
		$transport = $this->createMock(GatewayTransport::class);
		$transport->method('send')->willReturn(GatewayDelivery::refused('postex', 'campaign closed'));

		$result = (new PostexProvider($transport))->send(['recipient' => 'x'], ['campaign' => 'besluiten']);

		$this->assertTrue($result->isRefused());
		$this->assertSame('campaign closed', $result->getError());
	}//end testARefusedSendIsARefusalWithItsReason()

	public function testAStatusPollAsksForTheEncodedMessageOnTheDefaultSource(): void {
		$transport = $this->createMock(GatewayTransport::class);
		$transport->expects($this->once())
			->method('send')
			->with('postex', [], ['source' => 'postex', 'endpoint' => '/messages/px%2F1%20a', 'method' => 'GET'])
			->willReturn(GatewayDelivery::delivered('postex', 'px/1 a'));

		$result = (new PostexProvider($transport))->status('px/1 a');

		$this->assertSame(DigitalPostResult::STATUS_DELIVERED, $result->getStatus());
		$this->assertSame('px/1 a', $result->getProviderReference());
	}//end testAStatusPollAsksForTheEncodedMessageOnTheDefaultSource()

	public function testAnUnansweredStatusPollKeepsTheMessageSent(): void {
		$transport = $this->createMock(GatewayTransport::class);
		$transport->method('send')->willReturn(GatewayDelivery::notSent('postex', 'timeout'));

		$result = (new PostexProvider($transport))->status('px-1', ['source' => 'other']);

		$this->assertFalse($result->isRefused());
		$this->assertSame(DigitalPostResult::STATUS_SENT, $result->getStatus());
		$this->assertSame('px-1', $result->getProviderReference());
	}//end testAnUnansweredStatusPollKeepsTheMessageSent()

	public function testPostexReceivesNothingAndHoldsNothingBack(): void {
		$transport = $this->createMock(GatewayTransport::class);
		$transport->expects($this->never())->method('send');
		$provider = new PostexProvider($transport);

		$this->assertSame([], $provider->pollInbound(['campaign' => 'besluiten']));
		$provider->statusRecorded('px-1', []);
	}//end testPostexReceivesNothingAndHoldsNothingBack()
}//end class
