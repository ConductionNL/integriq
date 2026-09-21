<?php

/**
 * REQ-OSI-012: an inline secret is refused where a reference belongs.
 *
 * The refusal is a schema `pattern` on `sender_identity.smimePrivateKeyRef`
 * rather than application code, per ADR-031 — a constraint the schema can
 * enforce applies to every writer at once, including the generic object API and
 * the UI, without either of them knowing about it.
 *
 * Both scenarios of REQ-OSI-012 claimed PHPUnit coverage and neither had any
 * (integriq#2104 review 5264751700, blocker 5). This is that coverage. It pins
 * the pattern's PRESENCE so a register edit cannot silently drop the guard, and
 * exercises its BEHAVIOUR against the real PCRE so a future edit cannot weaken
 * it into something that still looks like a guard.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

class SenderIdentityRefPatternTest extends TestCase {

	/**
	 * The effective `sender_identity` property map (base register + register.d).
	 *
	 * @return array<string, mixed> The properties.
	 */
	private function properties(): array {
		$root = dirname(__DIR__, 3);
		$descriptor = json_decode((string)file_get_contents($root . '/lib/Settings/integriq_register.json'), true);

		$fragments = glob($root . '/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragmentPath) {
			$fragment = json_decode((string)file_get_contents($fragmentPath), true);
			if (is_array($fragment) === true) {
				$descriptor = $this->deepMerge($descriptor, $fragment);
			}
		}

		return $descriptor['components']['schemas']['sender_identity']['properties'];

	}//end properties()

	/**
	 * Recursive deep merge — mirrors InitializeRegister::deepMergeConfig().
	 *
	 * @param array<mixed> $base The base.
	 * @param array<mixed> $overlay The overlay.
	 *
	 * @return array<mixed> The merged result.
	 */
	private function deepMerge(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			if (is_array($value) === true && isset($base[$key]) === true && is_array($base[$key]) === true) {
				$baseIsList = ($base[$key] === [] || array_keys($base[$key]) === range(0, (count($base[$key]) - 1)));
				$overlayIsList = ($value === [] || array_keys($value) === range(0, (count($value) - 1)));
				if ($baseIsList === true && $overlayIsList === true) {
					$base[$key] = array_merge($base[$key], $value);
				} else {
					$base[$key] = $this->deepMerge($base[$key], $value);
				}
			} else {
				$base[$key] = $value;
			}
		}

		return $base;

	}//end deepMerge()

	/**
	 * The reference field declares a pattern at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-012-an-inline-secret-is-refused-where-a-reference-belongs
	 */
	public function testTheReferenceFieldDeclaresAPattern(): void {
		$properties = $this->properties();

		$this->assertArrayHasKey('smimePrivateKeyRef', $properties);
		$this->assertArrayHasKey(
			'pattern',
			$properties['smimePrivateKeyRef'],
			'Dropping the pattern removes the only thing refusing a pasted private key.'
		);

	}//end testTheReferenceFieldDeclaresAPattern()

	/**
	 * PEM private key material is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-012-an-inline-secret-is-refused-where-a-reference-belongs
	 */
	public function testPastingAKeyWhereAReferenceBelongsIsRefused(): void {
		$pattern = $this->properties()['smimePrivateKeyRef']['pattern'];

		$pems = [
			"-----BEGIN PRIVATE KEY-----\nMIIEvQ==\n-----END PRIVATE KEY-----",
			"-----BEGIN RSA PRIVATE KEY-----\nMIIEpA==\n-----END RSA PRIVATE KEY-----",
			"-----BEGIN ENCRYPTED PRIVATE KEY-----\nMIIFHD==\n-----END ENCRYPTED PRIVATE KEY-----",
		];

		foreach ($pems as $pem) {
			$this->assertSame(
				0,
				preg_match('/' . str_replace('/', '\/', $pattern) . '/', $pem),
				'PEM material must not satisfy the reference pattern.'
			);
		}

	}//end testPastingAKeyWhereAReferenceBelongsIsRefused()

	/**
	 * A legitimate reference, and an empty one, are accepted.
	 *
	 * An identity that has not been enrolled yet is a normal state, so the empty
	 * string must pass — a pattern that refused it would make the guard fire on
	 * every unmigrated identity.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-012-an-inline-secret-is-refused-where-a-reference-belongs
	 */
	public function testALegitimateReferenceIsNeverRefused(): void {
		$pattern = $this->properties()['smimePrivateKeyRef']['pattern'];

		$accepted = [
			'',
			'{credentialRef}',
			'cred-9f2a1b7c-4e5d-4a1f-9c3b-2d8e7f0a1b2c',
			'openconnector:generic-apikey:42',
		];

		foreach ($accepted as $reference) {
			$this->assertSame(
				1,
				preg_match('/' . str_replace('/', '\/', $pattern) . '/', $reference),
				'A credential reference must satisfy the pattern: ' . var_export($reference, true)
			);
		}

	}//end testALegitimateReferenceIsNeverRefused()
}//end class
