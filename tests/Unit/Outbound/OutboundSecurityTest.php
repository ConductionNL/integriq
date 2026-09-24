<?php

/**
 * Unit tests for OutboundSecurityService — what protect() answers when it cannot
 * sign, and in particular that an unresolvable credential and an unconfigured
 * identity are not the same answer.
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
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the outbound S/MIME protection path.
 *
 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-011-signing-keeps-working-once-the-key-is-brokered
 */
class OutboundSecurityTest extends TestCase {

	/**
	 * An identity that wants to sign and has a certificate.
	 *
	 * @var array<string,mixed>
	 */
	private const SIGNING_IDENTITY = [
		'signOutgoing' => true,
		'smimeCertificate' => '-----BEGIN CERTIFICATE-----\nnot-a-real-certificate\n-----END CERTIFICATE-----',
	];

	/**
	 * Build the service with an identity service answering a fixed state.
	 *
	 * @param string $state The signing-material state to report.
	 *
	 * @return OutboundSecurityService The service.
	 */
	private function service(string $state): OutboundSecurityService {
		$identities = $this->createMock(SenderIdentityService::class);
		$identities->method('signingMaterial')->willReturn(['key' => null, 'state' => $state]);

		return new OutboundSecurityService(
			objectService: $this->createMock(ORObjectService::class),
			identities: $identities
		);

	}//end service()

	/**
	 * Protect one message under a given signing-material state.
	 *
	 * @param string $state The signing-material state to report.
	 *
	 * @return array{signed:bool,encrypted:bool,payload:string,detail:string} The outcome.
	 */
	private function protectUnder(string $state): array {
		return $this->service(state: $state)->protect(
			identity: self::SIGNING_IDENTITY,
			identityId: 'identity-1',
			recipient: 'dana@example.org',
			message: 'a message'
		);

	}//end protectUnder()

	/**
	 * A credential the broker cannot resolve says so, rather than claiming the
	 * identity has no key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-011-signing-keeps-working-once-the-key-is-brokered
	 */
	public function testAnUnresolvableCredentialSaysSo(): void {
		$result = $this->protectUnder(state: SenderIdentityService::SIGNING_KEY_UNRESOLVABLE);

		$this->assertFalse($result['signed']);
		$this->assertStringContainsString('could not be resolved', $result['detail']);

	}//end testAnUnresolvableCredentialSaysSo()

	/**
	 * An identity with no key at all keeps its own, different message.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-011-signing-keeps-working-once-the-key-is-brokered
	 */
	public function testAnIdentityWithNoKeySaysThatInstead(): void {
		$result = $this->protectUnder(state: SenderIdentityService::SIGNING_KEY_ABSENT);

		$this->assertFalse($result['signed']);
		$this->assertStringContainsString('carries no S/MIME private key', $result['detail']);

	}//end testAnIdentityWithNoKeySaysThatInstead()

	/**
	 * The two reasons are DIFFERENT text.
	 *
	 * This is the assertion the whole change rests on. While those two causes
	 * shared one message, a write-only field the signing path could no longer read
	 * was indistinguishable from an identity nobody had configured — and the
	 * message went out unsigned either way, so nothing else would have caught it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-011-signing-keeps-working-once-the-key-is-brokered
	 */
	public function testTheTwoFailureReasonsAreDistinguishable(): void {
		$unresolvable = $this->protectUnder(state: SenderIdentityService::SIGNING_KEY_UNRESOLVABLE);
		$absent = $this->protectUnder(state: SenderIdentityService::SIGNING_KEY_ABSENT);

		$this->assertNotSame($absent['detail'], $unresolvable['detail']);

	}//end testTheTwoFailureReasonsAreDistinguishable()

	/**
	 * An identity that does not sign is answered before any key is sought.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-011-signing-keeps-working-once-the-key-is-brokered
	 */
	public function testAnIdentityThatDoesNotSignIsAnsweredFirst(): void {
		$identities = $this->createMock(SenderIdentityService::class);
		$identities->expects($this->never())->method('signingMaterial');

		$service = new OutboundSecurityService(
			objectService: $this->createMock(ORObjectService::class),
			identities: $identities
		);

		$result = $service->protect(
			identity: ['signOutgoing' => false],
			identityId: 'identity-1',
			recipient: 'dana@example.org',
			message: 'a message'
		);

		$this->assertFalse($result['signed']);
		$this->assertSame('This identity does not sign.', $result['detail']);

	}//end testAnIdentityThatDoesNotSignIsAnsweredFirst()

	/**
	 * A missing certificate is its own answer, separate from a missing key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-011-signing-keeps-working-once-the-key-is-brokered
	 */
	public function testAMissingCertificateIsItsOwnAnswer(): void {
		$identities = $this->createMock(SenderIdentityService::class);
		$identities->expects($this->never())->method('signingMaterial');

		$service = new OutboundSecurityService(
			objectService: $this->createMock(ORObjectService::class),
			identities: $identities
		);

		$result = $service->protect(
			identity: ['signOutgoing' => true],
			identityId: 'identity-1',
			recipient: 'dana@example.org',
			message: 'a message'
		);

		$this->assertFalse($result['signed']);
		$this->assertStringContainsString('carries no S/MIME certificate', $result['detail']);

	}//end testAMissingCertificateIsItsOwnAnswer()
}//end class
