<?php

/**
 * Integriq — encryption on a signed-envelope source.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Stuf
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Stuf;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolverInterface;
use OCA\Integriq\Adapters\Digikoppeling\SignedSoapProfileInterface;
use OCA\Integriq\Exception\DigikoppelingException;
use OCA\Integriq\Service\Stuf\SignedEnvelopeDelivery;
use OCA\Integriq\Service\Stuf\SignedEnvelopeRefusals;
use OCA\Integriq\Service\Stuf\SignedEnvelopeRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A source with `encryption` on sends an encrypted body and opens the answer before verifying it (REQ-WSE-001).
 *
 * @covers \OCA\Integriq\Service\Stuf\SignedEnvelopeDelivery
 * @uses   \OCA\Integriq\Service\Stuf\SignedEnvelopeRequest
 *
 * @spec openspec/changes/sources-ws-security-encryption/specs/digikoppeling-adapter/spec.md#requirement-a-soap-source-encrypts-its-body-and-decrypts-the-answer-req-wse-001
 */
class SignedEnvelopeDeliveryEncryptionTest extends TestCase {

	/**
	 * The requests the mock transport received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $history = [];

	/**
	 * The profile double.
	 *
	 * @var SignedSoapProfileInterface&MockObject
	 */
	private SignedSoapProfileInterface&MockObject $profile;

	/**
	 * A delivery over the given answers.
	 *
	 * @param array<int, mixed> $responses The queued answers.
	 *
	 * @return SignedEnvelopeDelivery
	 */
	private function delivery(array $responses): SignedEnvelopeDelivery {
		$this->history = [];
		$stack = HandlerStack::create(new MockHandler($responses));
		$stack->push(Middleware::history($this->history));
		$this->profile = $this->createMock(SignedSoapProfileInterface::class);

		$refusals = $this->createMock(SignedEnvelopeRefusals::class);
		$refusals->method('signingRefused')->willReturnCallback(
			static fn (DigikoppelingException $cause): RuntimeException => new RuntimeException('refused: ' . $cause->getMessage())
		);

		return new SignedEnvelopeDelivery(
			new Client(['handler' => $stack]),
			$this->createMock(PkiOverheidCredentialResolverInterface::class),
			$this->profile,
			$refusals
		);
	}//end delivery()

	/**
	 * With encryption on, the encrypted request is posted and the answer is decrypted before it is verified.
	 *
	 * @return void
	 */
	public function testEncryptionOnSendsEncryptedAndOpensTheAnswer(): void {
		$delivery = $this->delivery(responses: [new Response(200, [], '<EncryptedAnswer/>')]);
		$this->profile->expects($this->never())->method('buildSignedRequest');
		$this->profile->expects($this->never())->method('verifyResponse');
		$this->profile->expects($this->once())->method('buildSignedEncryptedRequest')
			->with('cert-1', '<Envelope/>', 'PARTNER-PEM')
			->willReturn('<encrypted/>');
		$this->profile->expects($this->once())->method('decryptAndVerifyResponse')
			->with('cert-1', '<EncryptedAnswer/>')
			->willReturn('ref-9');

		$reference = $delivery->deliver(new SignedEnvelopeRequest(
			sourceConfiguration: [
				'certificateRef' => 'cert-1',
				'endpoint' => 'https://partner.example.nl',
				'encryption' => true,
				'partnerCertificate' => ' PARTNER-PEM ',
			],
			kenmerk: 'k-1',
			envelopeXml: '<Envelope/>'
		));

		self::assertSame('ref-9', $reference);
		self::assertSame('<encrypted/>', (string)$this->history[0]['request']->getBody());
	}//end testEncryptionOnSendsEncryptedAndOpensTheAnswer()

	/**
	 * Encryption on without a partner certificate is refused before anything is sent.
	 *
	 * @return void
	 */
	public function testEncryptionWithoutAPartnerCertificateSendsNothing(): void {
		$delivery = $this->delivery(responses: [new Response(200, [], '<Answer/>')]);
		$this->profile->expects($this->never())->method('buildSignedRequest');
		$this->profile->expects($this->never())->method('buildSignedEncryptedRequest');

		try {
			$delivery->deliver(new SignedEnvelopeRequest(
				sourceConfiguration: ['certificateRef' => 'cert-1', 'endpoint' => 'https://partner.example.nl', 'encryption' => 'true'],
				kenmerk: 'k-1',
				envelopeXml: '<Envelope/>'
			));
			self::fail('expected a refusal');
		} catch (RuntimeException $refusal) {
			self::assertSame('refused: WS-Security encryption is on but the source has no partner certificate to encrypt for.', $refusal->getMessage());
		}

		self::assertSame([], $this->history);
	}//end testEncryptionWithoutAPartnerCertificateSendsNothing()

	/**
	 * An answer that cannot be decrypted fails the call with the reason; nothing is passed on.
	 *
	 * @return void
	 */
	public function testAnUndecryptableAnswerFailsTheCall(): void {
		$delivery = $this->delivery(responses: [new Response(200, [], '<EncryptedAnswer/>')]);
		$this->profile->method('buildSignedEncryptedRequest')->willReturn('<encrypted/>');
		$this->profile->method('decryptAndVerifyResponse')->willThrowException(
			new DigikoppelingException(message: 'WS-Security decryption failed: the content key could not be decrypted')
		);

		$this->expectException(DigikoppelingException::class);
		$this->expectExceptionMessage('WS-Security decryption failed');
		$delivery->deliver(new SignedEnvelopeRequest(
			sourceConfiguration: [
				'certificateRef' => 'cert-1',
				'endpoint' => 'https://partner.example.nl',
				'encryption' => true,
				'partnerCertificate' => 'PEM',
			],
			kenmerk: 'k-1',
			envelopeXml: '<Envelope/>'
		));
	}//end testAnUndecryptableAnswerFailsTheCall()

	/**
	 * With encryption off the signed-only path is unchanged.
	 *
	 * @return void
	 */
	public function testEncryptionOffKeepsTheSignedPath(): void {
		$delivery = $this->delivery(responses: [new Response(200, [], '<Answer/>')]);
		$this->profile->expects($this->never())->method('buildSignedEncryptedRequest');
		$this->profile->expects($this->never())->method('decryptAndVerifyResponse');
		$this->profile->method('buildSignedRequest')->willReturn('<signed/>');
		$this->profile->expects($this->once())->method('verifyResponse')->with('<Answer/>')->willReturn('ref-1');

		$reference = $delivery->deliver(new SignedEnvelopeRequest(
			sourceConfiguration: [
				'certificateRef' => 'cert-1',
				'endpoint' => 'https://partner.example.nl',
				'encryption' => false,
				'partnerCertificate' => 'PEM',
			],
			kenmerk: 'k-1',
			envelopeXml: '<Envelope/>'
		));

		self::assertSame('ref-1', $reference);
		self::assertSame('<signed/>', (string)$this->history[0]['request']->getBody());
	}//end testEncryptionOffKeepsTheSignedPath()
}//end class
