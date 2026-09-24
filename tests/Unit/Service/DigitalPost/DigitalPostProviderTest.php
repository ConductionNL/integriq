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

use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxClient;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxClientMock;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxClientUnavailable;
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
 * REQ-DPA-001, REQ-DPA-004, REQ-DPA-005 and REQ-DPA-006.
 *
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-one-provider-seam-with-log-berichtenbox-and-postex-bindings-req-dpa-001
 */
class DigitalPostProviderTest extends TestCase {
	/**
	 * A configured Berichtenbox source.
	 *
	 * @return array<string,mixed> The configuration.
	 */
	private function berichtenboxConfig(): array {
		return ['certificateRef' => 'pki-services-2026', 'senderOin' => '00000001234567890000'];
	}//end berichtenboxConfig()

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
	 * A source with no certificate cannot activate Berichtenbox, and the
	 * refusal names the certificate.
	 *
	 * @return void
	 */
	public function testASourceWithoutACertificateCannotActivateBerichtenbox(): void {
		$provider = new BerichtenboxProvider(new BerichtenboxClientMock(), $this->createMock(LoggerInterface::class));

		$refusals = $provider->activationRefusals(['senderOin' => '00000001234567890000']);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('certificateRef', $refusals[0]);
	}//end testASourceWithoutACertificateCannotActivateBerichtenbox()

	/**
	 * A source with no sender OIN cannot activate either, and the refusal
	 * names the OIN rather than the certificate.
	 *
	 * @return void
	 */
	public function testASourceWithoutASenderOinCannotActivateEither(): void {
		$provider = new BerichtenboxProvider(new BerichtenboxClientMock(), $this->createMock(LoggerInterface::class));

		$refusals = $provider->activationRefusals(['certificateRef' => 'pki-services-2026']);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('senderOin', $refusals[0]);
	}//end testASourceWithoutASenderOinCannotActivateEither()

	/**
	 * A send names the certificate reference, and no certificate or key
	 * appears in the call arguments.
	 *
	 * @return void
	 */
	public function testASendNamesACertificateReferenceNeverAKey(): void {
		$client = $this->getMockBuilder(BerichtenboxClient::class)
			->onlyMethods(['flavour', 'dispatch', 'verifyWebhook', 'checkMailbox'])
			->getMock();
		$client->method('flavour')->willReturn('mock');
		$client->expects($this->once())
			->method('dispatch')
			->with(
				$this->callback(
					static function (array $envelope): bool {
						// The envelope carries the letter, and nothing that
						// looks remotely like signing material.
						$flat = json_encode($envelope);

						return str_contains((string)$flat, 'BEGIN CERTIFICATE') === false
							&& str_contains((string)$flat, 'PRIVATE KEY') === false;
					}
				),
				'pki-services-2026'
			)
			->willReturn(['logiusKenmerk' => 'bbk-1', 'deliveryStatus' => 'queued']);

		$provider = new BerichtenboxProvider($client, $this->createMock(LoggerInterface::class));
		$result = $provider->send($this->letter(), $this->berichtenboxConfig());

		$this->assertFalse($result->isRefused());
		$this->assertSame('bbk-1', $result->getProviderReference());
	}//end testASendNamesACertificateReferenceNeverAKey()

	/**
	 * An unavailable credential broker fails the send closed, with the
	 * broker's reason, and no message reaches sent.
	 *
	 * @return void
	 */
	public function testAnUnavailableBrokerFailsClosed(): void {
		$client = $this->getMockBuilder(BerichtenboxClient::class)
			->onlyMethods(['flavour', 'dispatch', 'verifyWebhook', 'checkMailbox'])
			->getMock();
		$client->method('flavour')->willReturn('https');
		$client->method('dispatch')->willThrowException(
			new RuntimeException('The credential broker cannot supply in-process PKIoverheid signing material.')
		);

		$result = (new BerichtenboxProvider($client, $this->createMock(LoggerInterface::class)))
			->send($this->letter(), $this->berichtenboxConfig());

		$this->assertTrue($result->isRefused());
		$this->assertSame(DigitalPostResult::STATUS_FAILED, $result->getStatus());
		$this->assertStringContainsString('credential broker', $result->getError());
	}//end testAnUnavailableBrokerFailsClosed()

	/**
	 * A send without a certificate is refused by the binding itself, and the
	 * client is never called.
	 *
	 * @return void
	 */
	public function testASendWithoutACertificateNeverReachesTheClient(): void {
		$client = $this->getMockBuilder(BerichtenboxClient::class)
			->onlyMethods(['flavour', 'dispatch', 'verifyWebhook', 'checkMailbox'])
			->getMock();
		$client->expects($this->never())->method('dispatch');

		$result = (new BerichtenboxProvider($client, $this->createMock(LoggerInterface::class)))
			->send($this->letter(), ['senderOin' => '00000001234567890000']);

		$this->assertTrue($result->isRefused());
		$this->assertStringContainsString('certificateRef', $result->getError());
	}//end testASendWithoutACertificateNeverReachesTheClient()

	/**
	 * A mock-backed send says simulated, so a screen can show that the letter
	 * never left the instance.
	 *
	 * @return void
	 */
	public function testAMockBackedSendSaysSimulated(): void {
		$result = (new BerichtenboxProvider(new BerichtenboxClientMock(), $this->createMock(LoggerInterface::class)))
			->send($this->letter(), $this->berichtenboxConfig());

		$this->assertTrue($result->isSimulated());
	}//end testAMockBackedSendSaysSimulated()

	/**
	 * A flagged instance with no live client refuses the send and names what
	 * is missing. It does not serve the mock.
	 *
	 * @return void
	 */
	public function testAFlaggedInstanceWithoutCredentialsRefusesRatherThanSimulating(): void {
		$result = (new BerichtenboxProvider(new BerichtenboxClientUnavailable(), $this->createMock(LoggerInterface::class)))
			->send($this->letter(), $this->berichtenboxConfig());

		$this->assertTrue($result->isRefused());
		$this->assertFalse($result->isSimulated(), 'A refusal is not a simulation.');
		$this->assertStringContainsString('logius.berichtenbox.feature_flag', $result->getError());
		$this->assertStringContainsString('Nothing was sent', $result->getError());
	}//end testAFlaggedInstanceWithoutCredentialsRefusesRatherThanSimulating()

	/**
	 * The unavailable binding never answers a webhook as verified, which a
	 * caller could otherwise read as a checked signature.
	 *
	 * @return void
	 */
	public function testTheUnavailableBindingRefusesToVerifyAWebhook(): void {
		$this->expectException(RuntimeException::class);

		(new BerichtenboxClientUnavailable())->verifyWebhook('{}', []);
	}//end testTheUnavailableBindingRefusesToVerifyAWebhook()

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
