<?php

/**
 * Unit tests for OutboundSecurityService's working S/MIME path: signing with a
 * real (throwaway, self-signed) key pair, encrypting to a recipient key looked
 * up in OpenRegister, verifying a signed message, and how a recipient key is
 * chosen from the lookup.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound\Identity;

use OCA\Integriq\Outbound\Identity\OutboundSecurityService;
use OCA\Integriq\Outbound\Identity\SenderIdentityService;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for signing, encryption, verification and recipient-key lookup.
 *
 * @covers \OCA\Integriq\Outbound\Identity\OutboundSecurityService
 */
class OutboundSecurityServiceTest extends TestCase {

	/**
	 * PEM certificate of the throwaway key pair.
	 *
	 * @var string
	 */
	private static string $certificate = '';

	/**
	 * PEM private key of the throwaway key pair.
	 *
	 * @var string
	 */
	private static string $privateKey = '';

	/**
	 * OpenRegister object service mock.
	 *
	 * @var ORObjectService&MockObject
	 */
	private ORObjectService&MockObject $objectService;

	/**
	 * Create one self-signed key pair for the whole class.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$csr = openssl_csr_new(['commonName' => 'sender.test', 'emailAddress' => 'sender@sender.test'], $key);
		$x509 = openssl_csr_sign($csr, null, $key, 1);
		openssl_x509_export($x509, self::$certificate);
		openssl_pkey_export($key, self::$privateKey);

	}//end setUpBeforeClass()

	/**
	 * Fresh mocks per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ORObjectService::class);

	}//end setUp()

	/**
	 * Build the service with an identity service handing out the given key.
	 *
	 * @param string|null $key The private key to report as available.
	 *
	 * @return OutboundSecurityService The service.
	 */
	private function service(?string $key = null): OutboundSecurityService {
		$identities = $this->createMock(SenderIdentityService::class);
		$identities->method('signingMaterial')->willReturn(
			['key' => ($key ?? self::$privateKey), 'state' => SenderIdentityService::SIGNING_KEY_AVAILABLE]
		);

		return new OutboundSecurityService(objectService: $this->objectService, identities: $identities);

	}//end service()

	/**
	 * A recipient_key row as OpenRegister returns it.
	 *
	 * @param array<string,mixed> $object The row's object data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function keyRow(array $object): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($object);

		return $entity;

	}//end keyRow()

	/**
	 * Protect a message for dana@example.org with the signing identity.
	 *
	 * @param OutboundSecurityService $service The service.
	 *
	 * @return array{signed:bool,encrypted:bool,payload:string,detail:string} The outcome.
	 */
	private function protect(OutboundSecurityService $service): array {
		return $service->protect(
			identity: ['signOutgoing' => true, 'smimeCertificate' => self::$certificate],
			identityId: 'identity-1',
			recipient: 'dana@example.org',
			message: "Subject: hello\r\n\r\nA message body."
		);

	}//end protect()

	/**
	 * Without a recipient key the message is signed but not encrypted, and the
	 * signature verifies against the sender certificate.
	 *
	 * @return void
	 */
	public function testSignsWithoutEncryptingWhenNoRecipientKeyIsKnown(): void {
		$this->objectService->method('findAll')->willReturn(['results' => []]);
		$service = $this->service();

		$result = $this->protect(service: $service);

		$this->assertTrue($result['signed']);
		$this->assertFalse($result['encrypted']);
		$this->assertSame('Signed, not encrypted: no public key is known for this recipient.', $result['detail']);
		$this->assertStringContainsString('application/x-pkcs7-signature', $result['payload']);
		$this->assertStringContainsString('A message body.', $result['payload']);

		$this->assertSame(
			['verified' => true, 'detail' => 'Signature verified against the sender certificate.'],
			$service->verify(message: $result['payload'])
		);

	}//end testSignsWithoutEncryptingWhenNoRecipientKeyIsKnown()

	/**
	 * With a usable recipient key the signed message is encrypted too.
	 *
	 * @return void
	 */
	public function testSignsAndEncryptsWithAUsableRecipientKey(): void {
		$this->objectService->method('findAll')->willReturn(
			['results' => [$this->keyRow(['address' => 'dana@example.org', 'publicKey' => self::$certificate])]]
		);

		$result = $this->protect(service: $this->service());

		$this->assertTrue($result['signed']);
		$this->assertTrue($result['encrypted']);
		$this->assertSame('Signed and encrypted.', $result['detail']);
		$this->assertStringContainsString('application/x-pkcs7-mime', $result['payload']);
		$this->assertStringNotContainsString('A message body.', $result['payload']);

	}//end testSignsAndEncryptsWithAUsableRecipientKey()

	/**
	 * An unusable recipient key leaves the message signed only.
	 *
	 * @return void
	 */
	public function testAnUnusableRecipientKeyLeavesTheMessageSignedOnly(): void {
		$this->objectService->method('findAll')->willReturn(
			['results' => [$this->keyRow(['address' => 'dana@example.org', 'publicKey' => 'not a certificate'])]]
		);

		$result = $this->protect(service: $this->service());

		$this->assertTrue($result['signed']);
		$this->assertFalse($result['encrypted']);
		$this->assertSame('Signed, not encrypted: the recipient key could not be used.', $result['detail']);
		$this->assertStringContainsString('application/x-pkcs7-signature', $result['payload']);

	}//end testAnUnusableRecipientKeyLeavesTheMessageSignedOnly()

	/**
	 * A private key that cannot sign returns the original message, unsigned,
	 * and never looks up a recipient key.
	 *
	 * @return void
	 */
	public function testAKeyThatCannotSignSendsTheMessageUnsigned(): void {
		$this->objectService->expects($this->never())->method('findAll');

		$result = $this->protect(service: $this->service(key: 'not a private key'));

		$this->assertSame(
			[
				'signed'    => false,
				'encrypted' => false,
				'payload'   => "Subject: hello\r\n\r\nA message body.",
				'detail'    => 'Signing failed; the message was not sent signed.',
			],
			$result
		);

	}//end testAKeyThatCannotSignSendsTheMessageUnsigned()

	/**
	 * A tampered or unsigned message does not verify.
	 *
	 * @return void
	 */
	public function testATamperedOrUnsignedMessageDoesNotVerify(): void {
		$this->objectService->method('findAll')->willReturn(['results' => []]);
		$service = $this->service();
		$signed  = $this->protect(service: $service)['payload'];

		$tampered = str_replace('A message body.', 'A forged body!!', $signed);

		$this->assertSame(
			['verified' => false, 'detail' => 'The signature did not verify.'],
			$service->verify(message: $tampered)
		);
		$this->assertSame(
			['verified' => false, 'detail' => 'The signature did not verify.'],
			$service->verify(message: "Subject: plain\r\n\r\nNot signed.")
		);

	}//end testATamperedOrUnsignedMessageDoesNotVerify()

	/**
	 * The lookup filters on the integriq register, the key schema and the
	 * normalised address, and returns the trimmed key of the first matching row.
	 *
	 * @return void
	 */
	public function testRecipientKeyQueriesTheNormalisedAddressAndSkipsUnusableRows(): void {
		$this->objectService->expects($this->once())
			->method('findAll')
			->with(
				[
					'filters' => [
						'register' => MessageRecorder::REGISTER,
						'schema'   => 'recipient_key',
						'address'  => 'dana@example.org',
					],
				]
			)
			->willReturn(
				[
					'results' => [
						['address' => 'dana@example.org', 'publicKey' => 'array, not an entity'],
						$this->keyRow(['address' => 'someone@else.org', 'publicKey' => 'KEY-OTHER']),
						$this->keyRow(['address' => 'Dana@Example.org', 'publicKey' => '   ']),
						$this->keyRow(['address' => 'DANA@example.org', 'publicKey' => "  KEY-DANA \n"]),
						$this->keyRow(['address' => 'dana@example.org', 'publicKey' => 'KEY-LATER']),
					],
				]
			);

		$this->assertSame('KEY-DANA', $this->service()->recipientKey(recipient: '  Dana@Example.ORG '));

	}//end testRecipientKeyQueriesTheNormalisedAddressAndSkipsUnusableRows()

	/**
	 * A plain list without a results wrapper is read as the rows themselves.
	 *
	 * @return void
	 */
	public function testRecipientKeyReadsAnUnwrappedList(): void {
		$this->objectService->method('findAll')->willReturn(
			[$this->keyRow(['address' => 'dana@example.org', 'publicKey' => 'KEY-DANA'])]
		);

		$this->assertSame('KEY-DANA', $this->service()->recipientKey(recipient: 'dana@example.org'));

	}//end testRecipientKeyReadsAnUnwrappedList()

	/**
	 * Non-array results and rows without a usable key give no key.
	 *
	 * @return void
	 */
	public function testRecipientKeyIsNullWhenNothingUsableIsFound(): void {
		$this->objectService->method('findAll')->willReturnOnConsecutiveCalls(
			['results' => 'not a list'],
			['results' => [$this->keyRow(['address' => 'dana@example.org'])]]
		);
		$service = $this->service();

		$this->assertNull($service->recipientKey(recipient: 'dana@example.org'));
		$this->assertNull($service->recipientKey(recipient: 'dana@example.org'));

	}//end testRecipientKeyIsNullWhenNothingUsableIsFound()
}//end class
