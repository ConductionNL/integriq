<?php

/**
 * Integriq — digital post binding tests.
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

use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxClientMock;
use OCA\Integriq\Gateway\GatewayDelivery;
use OCA\Integriq\Gateway\GatewayTransport;
use OCA\Integriq\Service\DigitalPost\BerichtenboxProvider;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry;
use OCA\Integriq\Service\DigitalPost\DigitalPostResult;
use OCA\Integriq\Service\DigitalPost\LogDigitalPostProvider;
use OCA\Integriq\Service\DigitalPost\PostexProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-DPA-001. The Berichtenbox binding has its own test, BerichtenboxProviderTest.
 *
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-one-provider-seam-with-log-berichtenbox-and-postex-bindings-req-dpa-001
 */
class DigitalPostProviderTest extends TestCase {
	/**
	 * A letter.
	 *
	 * @return array<string,mixed> The message.
	 */
	private function letter(): array {
		return [
			'recipient' => '999993653',
			'subject' => 'Uw aanvraag',
			'body' => 'Beste heer De Vries,',
			'attachments' => [['name' => 'besluit.pdf', 'url' => 'https://example.test/besluit.pdf']],
		];
	}//end letter()

	/**
	 * The log binding reaches delivered, and says simulated.
	 *
	 * @return void
	 */
	public function testTheLogBindingAnswersDeliveredAndSaysSimulated(): void {
		$result = (new LogDigitalPostProvider($this->createMock(LoggerInterface::class)))->send($this->letter());

		$this->assertSame(DigitalPostResult::STATUS_DELIVERED, $result->getStatus());
		$this->assertTrue($result->isSimulated());
	}//end testTheLogBindingAnswersDeliveredAndSaysSimulated()

	/**
	 * Postex sends over the shared transport, and carries no client of its own.
	 *
	 * @return void
	 */
	public function testPostexSendsOverTheSharedTransport(): void {
		$transport = $this->createMock(GatewayTransport::class);
		$transport->expects($this->once())
			->method('send')
			->with('postex', $this->anything(), $this->anything())
			->willReturn(GatewayDelivery::delivered('postex', 'px-1'));

		$result = (new PostexProvider($transport))->send($this->letter(), ['campaign' => 'besluiten-2026']);

		$this->assertSame(DigitalPostResult::STATUS_SENT, $result->getStatus());
		$this->assertSame('px-1', $result->getProviderReference());
	}//end testPostexSendsOverTheSharedTransport()

	/**
	 * A Postex source without a campaign cannot activate, and sends nothing.
	 *
	 * @return void
	 */
	public function testPostexWithoutACampaignCannotActivate(): void {
		$transport = $this->createMock(GatewayTransport::class);
		$transport->expects($this->never())->method('send');

		$result = (new PostexProvider($transport))->send($this->letter(), []);

		$this->assertTrue($result->isRefused());
		$this->assertStringContainsString('campaign', $result->getError());
	}//end testPostexWithoutACampaignCannotActivate()

	/**
	 * A provider id nothing answers to fails naming itself and the ids that do
	 * exist, rather than falling back to the log binding.
	 *
	 * @return void
	 */
	public function testAnUnknownProviderIdFailsRatherThanFallingBackToTheLog(): void {
		$registry = new DigitalPostProviderRegistry(
			[new LogDigitalPostProvider($this->createMock(LoggerInterface::class))]
		);

		$this->assertFalse($registry->has('berichtenbox'));

		try {
			$registry->get('berichtenbox');
			$this->fail('An unknown provider id must not resolve to anything.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('berichtenbox', $e->getMessage());
			$this->assertStringContainsString('log', $e->getMessage());
			$this->assertStringContainsString('Nothing was sent', $e->getMessage());
		}
	}//end testAnUnknownProviderIdFailsRatherThanFallingBackToTheLog()

	/**
	 * The registry describes every binding with the configuration it needs, so
	 * a source form can be built from it.
	 *
	 * @return void
	 */
	public function testTheRegistryDescribesWhatEachBindingNeeds(): void {
		$registry = new DigitalPostProviderRegistry(
			[
				new BerichtenboxProvider(new BerichtenboxClientMock(), $this->createMock(LoggerInterface::class)),
				new LogDigitalPostProvider($this->createMock(LoggerInterface::class)),
			]
		);

		$described = $registry->describeAll();

		$this->assertSame(['berichtenbox', 'log'], array_column($described, 'providerId'));
		$this->assertSame(
			['certificateRef', 'senderOin'],
			$described[0]['configSchema']['required']
		);
	}//end testTheRegistryDescribesWhatEachBindingNeeds()
}//end class
