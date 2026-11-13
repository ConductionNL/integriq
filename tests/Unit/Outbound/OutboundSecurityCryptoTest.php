<?php

/**
 * Unit tests for OutboundSecurityService with real S/MIME material.
 *
 * OutboundSecurityTest covers every answer protect() gives before it reaches
 * OpenSSL. This file generates a throwaway key pair and self-signed
 * certificate and drives the rest for real: signing, encrypting to a known
 * recipient key, each fallback when a step cannot be done, verifying a signed
 * payload, the recipient-key lookup, and that no temporary file is left behind.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound;

use OCA\Integriq\Outbound\Identity\OutboundSecurityService;
use OCA\Integriq\Outbound\Identity\SenderIdentityService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Signing, encryption, verification and recipient-key lookup.
 *
 * @covers \OCA\Integriq\Outbound\Identity\OutboundSecurityService
 *
 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md
 */
final class OutboundSecurityCryptoTest extends TestCase {

	/**
	 * PEM certificate and private key of the throwaway signer.
	 *
	 * @var array{cert: string, key: string}
	 */
	private static array $signer;

	/**
	 * PEM certificate of a throwaway recipient.
	 *
	 * @var string
	 */
	private static string $recipientCert;

	/**
	 * @var ORObjectService&MockObject
	 */
	private ORObjectService $objects;

	/**
	 * Generate the key material once for the class.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		self::$signer = self::selfSigned('sender@example.org');
		self::$recipientCert = self::selfSigned('dana@example.org')['cert'];
	}//end setUpBeforeClass()

	/**
	 * A fresh object-service double per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ORObjectService::class);
	}//end setUp()

	/**
	 * A self-signed certificate and its private key, in PEM.
	 *
	 * @param string $email The certificate's e-mail address.
	 *
	 * @return array{cert: string, key: string}
	 */
	private static function selfSigned(string $email): array {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$csr = openssl_csr_new(['commonName' => $email, 'emailAddress' => $email], $key, ['digest_alg' => 'sha256']);
		$cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
		openssl_x509_export($cert, $certPem);
		openssl_pkey_export($key, $keyPem);

		return ['cert' => $certPem, 'key' => $keyPem];
	}//end selfSigned()

	/**
	 * The service with signing material in the given state.
	 *
	 * @param string|null $key   The private key handed back.
	 * @param string      $state The signing-material state.
	 *
	 * @return OutboundSecurityService
	 */
	private function service(?string $key, string $state = SenderIdentityService::SIGNING_KEY_AVAILABLE): OutboundSecurityService {
		$identities = $this->createMock(SenderIdentityService::class);
		$identities->method('signingMaterial')->willReturn(['key' => $key, 'state' => $state]);

		return new OutboundSecurityService(objectService: $this->objects, identities: $identities);
	}//end service()

	/**
	 * A stored recipient-key row.
	 *
	 * @param string $address   The recipient address.
	 * @param string $publicKey The PEM public certificate.
	 *
	 * @return ObjectEntity
	 */
	private function keyRow(string $address, string $publicKey): ObjectEntity {
		$row = new ObjectEntity();
		$row->setObject(['address' => $address, 'publicKey' => $publicKey]);
		return $row;
	}//end keyRow()

	/**
	 * Protect a message for dana with the signer's certificate.
	 *
	 * @param OutboundSecurityService $service The service.
	 *
	 * @return array<string, mixed>
	 */
	private function protect(OutboundSecurityService $service): array {
		return $service->protect(
			identity: ['signOutgoing' => true, 'smimeCertificate' => self::$signer['cert']],
			identityId: 'identity-1',
			recipient: 'dana@example.org',
			message: "Content-Type: text/plain\r\n\r\nUw aanvraag is ontvangen."
		);
	}//end protect()

	/**
	 * The integriq S/MIME temp files currently on disk.
	 *
	 * @return array<int, string>
	 */
	private function tempFiles(): array {
		return (glob(sys_get_temp_dir() . '/integriq-smime-*') ?: []);
	}//end tempFiles()

	/**
	 * Without a known recipient key the message is signed only, and the signature verifies.
	 *
	 * @return void
	 */
	public function testItSignsWhenNoRecipientKeyIsKnownAndTheSignatureVerifies(): void {
		$this->objects->method('findAll')->willReturn(['results' => []]);
		$service = $this->service(key: self::$signer['key']);
		$before = $this->tempFiles();

		$result = $this->protect($service);

		$this->assertTrue($result['signed']);
		$this->assertFalse($result['encrypted']);
		$this->assertSame('Signed, not encrypted: no public key is known for this recipient.', $result['detail']);
		$this->assertStringContainsString('application/x-pkcs7-signature', $result['payload']);
		$this->assertStringContainsString('Uw aanvraag is ontvangen.', $result['payload']);
		$this->assertSame(
			['verified' => true, 'detail' => 'Signature verified against the sender certificate.'],
			$service->verify($result['payload'])
		);
		$this->assertSame($before, $this->tempFiles(), 'Every temporary file is removed again.');
	}//end testItSignsWhenNoRecipientKeyIsKnownAndTheSignatureVerifies()

	/**
	 * With a usable recipient key the signed message is also encrypted.
	 *
	 * @return void
	 */
	public function testItEncryptsToAKnownRecipientKey(): void {
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'integriq', 'schema' => 'recipient_key', 'address' => 'dana@example.org']])
			->willReturn(['results' => [$this->keyRow('Dana@Example.org', self::$recipientCert)]]);

		$result = $this->protect($this->service(key: self::$signer['key']));

		$this->assertTrue($result['signed']);
		$this->assertTrue($result['encrypted']);
		$this->assertSame('Signed and encrypted.', $result['detail']);
		$this->assertStringContainsString('application/x-pkcs7-mime', $result['payload']);
		$this->assertStringNotContainsString('Uw aanvraag is ontvangen.', $result['payload']);
	}//end testItEncryptsToAKnownRecipientKey()

	/**
	 * A recipient key OpenSSL cannot use leaves the message signed only.
	 *
	 * @return void
	 */
	public function testAnUnusableRecipientKeyLeavesTheMessageSignedOnly(): void {
		$this->objects->method('findAll')->willReturn([$this->keyRow('dana@example.org', 'not a certificate')]);

		$result = $this->protect($this->service(key: self::$signer['key']));

		$this->assertTrue($result['signed']);
		$this->assertFalse($result['encrypted']);
		$this->assertSame('Signed, not encrypted: the recipient key could not be used.', $result['detail']);
		$this->assertStringContainsString('application/x-pkcs7-signature', $result['payload']);
	}//end testAnUnusableRecipientKeyLeavesTheMessageSignedOnly()

	/**
	 * A private key that does not belong to the certificate fails the signing.
	 *
	 * @return void
	 */
	public function testAKeyThatCannotSignLeavesTheMessageUnsigned(): void {
		$this->objects->expects($this->never())->method('findAll');

		$result = $this->protect($this->service(key: 'not a private key'));

		$this->assertSame(
			['signed' => false, 'encrypted' => false, 'payload' => "Content-Type: text/plain\r\n\r\nUw aanvraag is ontvangen.", 'detail' => 'Signing failed; the message was not sent signed.'],
			$result
		);
	}//end testAKeyThatCannotSignLeavesTheMessageUnsigned()

	/**
	 * A payload that is not a valid signature does not verify.
	 *
	 * @return void
	 */
	public function testAnUnsignedPayloadDoesNotVerify(): void {
		$before = $this->tempFiles();

		$this->assertSame(
			['verified' => false, 'detail' => 'The signature did not verify.'],
			$this->service(key: null)->verify("Content-Type: text/plain\r\n\r\nNo signature here.")
		);
		$this->assertSame($before, $this->tempFiles());
	}//end testAnUnsignedPayloadDoesNotVerify()

	/**
	 * The lookup skips foreign rows, other addresses and blank keys, and trims the match.
	 *
	 * @return void
	 */
	public function testRecipientKeySkipsRowsThatDoNotApply(): void {
		$this->objects->method('findAll')->willReturn(
			[
				'results' => [
					['address' => 'dana@example.org', 'publicKey' => 'ARRAY-ROW'],
					$this->keyRow('someone@example.org', 'OTHER'),
					$this->keyRow('dana@example.org', '   '),
					$this->keyRow('dana@example.org', "  PEM-KEY \n"),
				],
			]
		);

		$this->assertSame('PEM-KEY', $this->service(key: null)->recipientKey('  DANA@example.org '));
	}//end testRecipientKeySkipsRowsThatDoNotApply()

	/**
	 * A lookup that answers no usable list finds no key.
	 *
	 * @return void
	 */
	public function testRecipientKeyIsNullWithoutAUsableList(): void {
		$this->objects->method('findAll')->willReturnOnConsecutiveCalls(['results' => 'oops'], [], ['results' => [$this->keyRow('dana@example.org', '')]]);
		$service = $this->service(key: null);

		$this->assertNull($service->recipientKey('dana@example.org'));
		$this->assertNull($service->recipientKey('dana@example.org'));
		$this->assertNull($service->recipientKey('dana@example.org'));
	}//end testRecipientKeyIsNullWithoutAUsableList()
}//end class
