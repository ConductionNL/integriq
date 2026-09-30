<?php

/**
 * A sender identity references a Nextcloud Mail account and holds none of
 * its credentials (outbound-sender-identity-and-deliverability, decision D12,
 * task 10.2).
 *
 * D12 puts the mail account in Nextcloud Mail: the account holds the
 * password and the OAuth grant, the identity only what a recipient sees. This
 * reads the merged register, the schema OpenRegister actually stores, so a
 * later fragment that adds a credential to the identity fails here.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md#requirement-req-osi-001-more-than-one-outbound-sender-identity
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * The identity is a face on an account somebody else owns.
 */
class SenderIdentityHoldsNoMailCredentialTest extends TestCase {

	/**
	 * Property names that would mean the identity holds a mail credential.
	 */
	private const CREDENTIAL_WORDS = ['password', 'secret', 'token', 'oauth', 'smtp', 'imap', 'username', 'credential'];

	/**
	 * The merged sender_identity schema.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function schema(): array {
		$schema = (RegisterSchemaValidator::descriptor()['components']['schemas']['sender_identity'] ?? null);
		$this->assertIsArray($schema, 'The register declares sender_identity.');

		return $schema;
	}//end schema()

	/**
	 * The account is referenced by a single string, never embedded.
	 *
	 * @return void
	 */
	public function testTheIdentityReferencesItsMailAccount(): void {
		$this->assertSame('string', $this->schema()['properties']['mailAccount']['type']);
	}//end testTheIdentityReferencesItsMailAccount()

	/**
	 * No property of the identity is a mail credential. The S/MIME private
	 * key signs what the identity sends; it logs in nowhere, and it is
	 * write-only.
	 *
	 * @return void
	 */
	public function testNoPropertyHoldsAMailCredential(): void {
		$properties = $this->schema()['properties'];
		foreach (array_keys($properties) as $name) {
			foreach (self::CREDENTIAL_WORDS as $word) {
				$this->assertStringNotContainsStringIgnoringCase($word, $name, 'sender_identity.' . $name . ' looks like a mail credential (D12).');
			}
		}

		$this->assertTrue(($properties['smimePrivateKey']['writeOnly'] ?? false), 'The signing key is never read back.');
	}//end testNoPropertyHoldsAMailCredential()
}//end class
