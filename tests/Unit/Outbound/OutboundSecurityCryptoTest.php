<?php

/**
 * Unit tests for OutboundSecurityService with real S/MIME material: a message
 * is signed with the identity's key, encrypted to the recipient's certificate
 * when one is known, and a signed message verifies on the way back in.
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
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound;

use OCA\Integriq\Outbound\Identity\OutboundSecurityService;
use OCA\Integriq\Outbound\Identity\SenderIdentityService;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Sign, encrypt, verify and recipient-key lookup.
 *
 * @covers \OCA\Integriq\Outbound\Identity\OutboundSecurityService
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */
class OutboundSecurityCryptoTest extends TestCase {

	private const MESSAGE = "Content-Type: text/plain\r\n\r\nUw aanvraag is ontvangen.\r\n";

	/**
	 * The sender's and the recipient's material, as [certificate, private key] PEM pairs.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private static array $material = [];

	/**
	 * Make two self-signed certificates once for the class.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		foreach (['sender' => 'noreply@example.org', 'recipient' => 'dana@example.org'] as $who => $address) {
			$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
			$csr = openssl_csr_new(['commonName' => $address, 'emailAddress' => $address], $key);
			$cert = openssl_csr_sign($csr, null, $key, 1);
			openssl_x509_export($cert, $certificatePem);
			openssl_pkey_export($key, $keyPem);
			self::$material[$who] = [$certificatePem, $keyPem];
		}
	}//end setUpBeforeClass()

	/**
	 * The service with a signing key and the given recipient-key rows.
	 *
	 * @param string                    $signingKey The key the broker hands out.
	 * @param array<int|string, mixed>  $found      What findAll answers.
	 *
	 * @return OutboundSecurityService
	 */
	private function service(string $signingKey, array $found=['results' => []]): OutboundSecurityService {
		$identities = $this->createMock(SenderIdentityService::class);
		$identities->method('signingMaterial')->willReturn(['key' => $signingKey, 'state' => SenderIdentityService::SIGNING_KEY_AVAILABLE]);

		$objects = $this->createMock(ORObjectService::class);
		$objects->method('findAll')->willReturn($found);

		return new OutboundSecurityService(objectService: $objects, identities: $identities);
	}//end service()

	/**
	 * A stored recipient key row.
	 *
	 * @param array<string, mixed> $object The row's object.
	 *
	 * @return ObjectEntity
	 */
	private function row(array $object): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($object);
		return $entity;
	}//end row()

	/**
	 * Protect the message for dana.
	 *
	 * @param OutboundSecurityService $service The service.
	 *
	 * @return array{signed:bool,encrypted:bool,payload:string,detail:string}
	 */
	private function protect(OutboundSecurityService $service): array {
		return $service->protect(
			identity: ['signOutgoing' => true, 'smimeCertificate' => self::$material['sender'][0]],
			identityId: 'identity-1',
			recipient: 'Dana@Example.org ',
			message: self::MESSAGE
		);
	}//end protect()

	/**
	 * Without a recipient key the message is signed only, and the signature verifies.
	 *
	 * @return void
	 */
	public function testASignedMessageVerifiesAgainstTheSenderCertificate(): void {
		$service = $this->service(signingKey: self::$material['sender'][1]);
		$result = $this->protect($service);

		$this->assertTrue($result['signed']);
		$this->assertFalse($result['encrypted']);
		$this->assertSame('Signed, not encrypted: no public key is known for this recipient.', $result['detail']);
		$this->assertStringContainsString('application/x-pkcs7-signature', $result['payload']);

		$this->assertSame(
			['verified' => true, 'detail' => 'Signature verified against the sender certificate.'],
			$service->verify($result['payload'])
		);
	}//end testASignedMessageVerifiesAgainstTheSenderCertificate()

	/**
	 * With a recipient key the signed message is encrypted, and only the recipient can open it.
	 *
	 * @return void
	 */
	public function testAKnownRecipientGetsASignedAndEncryptedMessage(): void {
		$service = $this->service(
			signingKey: self::$material['sender'][1],
			found: ['results' => [$this->row(['address' => 'dana@example.org', 'publicKey' => self::$material['recipient'][0]])]]
		);
		$result = $this->protect($service);

		$this->assertTrue($result['signed']);
		$this->assertTrue($result['encrypted']);
		$this->assertSame('Signed and encrypted.', $result['detail']);
		$this->assertStringNotContainsString('Uw aanvraag', $result['payload']);

		$in = (string)tempnam(sys_get_temp_dir(), 'test-smime-');
		$out = (string)tempnam(sys_get_temp_dir(), 'test-smime-');
		file_put_contents($in, $result['payload']);
		$this->assertTrue(openssl_pkcs7_decrypt($in, $out, self::$material['recipient'][0], self::$material['recipient'][1]));
		$opened = (string)file_get_contents($out);
		unlink($in);
		unlink($out);

		$this->assertTrue($service->verify($opened)['verified']);
	}//end testAKnownRecipientGetsASignedAndEncryptedMessage()

	/**
	 * A key that does not belong to the certificate cannot sign.
	 *
	 * @return void
	 */
	public function testAKeyThatDoesNotMatchTheCertificateDoesNotSign(): void {
		$result = $this->protect($this->service(signingKey: self::$material['recipient'][1]));

		$this->assertSame(
			['signed' => false, 'encrypted' => false, 'payload' => self::MESSAGE, 'detail' => 'Signing failed; the message was not sent signed.'],
			$result
		);
	}//end testAKeyThatDoesNotMatchTheCertificateDoesNotSign()

	/**
	 * A stored recipient key that is not a certificate leaves the message signed only.
	 *
	 * @return void
	 */
	public function testAnUnusableRecipientKeyLeavesTheMessageSigned(): void {
		$result = $this->protect(
			$this->service(
				signingKey: self::$material['sender'][1],
				found: ['results' => [$this->row(['address' => 'dana@example.org', 'publicKey' => 'not a certificate'])]]
			)
		);

		$this->assertTrue($result['signed']);
		$this->assertFalse($result['encrypted']);
		$this->assertSame('Signed, not encrypted: the recipient key could not be used.', $result['detail']);
	}//end testAnUnusableRecipientKeyLeavesTheMessageSigned()

	/**
	 * An unsigned message does not verify.
	 *
	 * @return void
	 */
	public function testAnUnsignedMessageDoesNotVerify(): void {
		$this->assertSame(
			['verified' => false, 'detail' => 'The signature did not verify.'],
			$this->service(signingKey: '')->verify(self::MESSAGE)
		);
	}//end testAnUnsignedMessageDoesNotVerify()

	/**
	 * The lookup filters on the normalised address and takes the first matching row with a key.
	 *
	 * @return void
	 */
	public function testTheRecipientKeyIsTheFirstMatchingRowWithAKey(): void {
		$objects = $this->createMock(ORObjectService::class);
		$objects->expects($this->once())->method('findAll')->with(
			[
				'filters' => [
					'register' => MessageRecorder::REGISTER,
					'schema' => 'recipient_key',
					'address' => 'dana@example.org',
				],
			]
		)->willReturn(
			[
				'results' => [
					['address' => 'dana@example.org', 'publicKey' => 'an array, not a row'],
					$this->row(['address' => 'someone@example.org', 'publicKey' => 'other']),
					$this->row(['address' => 'dana@example.org', 'publicKey' => '  ']),
					$this->row(['address' => 'DANA@example.org', 'publicKey' => " the-key \n"]),
					$this->row(['address' => 'dana@example.org', 'publicKey' => 'later']),
				],
			]
		);
		$service = new OutboundSecurityService(objectService: $objects, identities: $this->createMock(SenderIdentityService::class));

		$this->assertSame('the-key', $service->recipientKey(' Dana@Example.org'));
	}//end testTheRecipientKeyIsTheFirstMatchingRowWithAKey()

	/**
	 * Results that are not a list, or a list without a match, give no key.
	 *
	 * @return void
	 */
	public function testNoMatchingRowGivesNoKey(): void {
		$this->assertNull($this->service(signingKey: '', found: ['results' => 'not a list'])->recipientKey('dana@example.org'));
		$this->assertNull($this->service(signingKey: '', found: [])->recipientKey('dana@example.org'));
	}//end testNoMatchingRowGivesNoKey()
}//end class
