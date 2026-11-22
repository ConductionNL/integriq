<?php

/**
 * The idpRegistration register fragment: its properties, its credential
 * references and its admin-only authorization.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
 */
class IdpRegistrationFragmentTest extends TestCase {

	/**
	 * The schema from the fragment.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema(): array {
		$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/40-idp-registration.json'), true);
		self::assertIsArray($fragment);
		self::assertSame(['idpRegistration'], $fragment['components']['registers']['integriq']['schemas']);

		return $fragment['components']['schemas']['idpRegistration'];
	}//end schema()

	/**
	 * Every property of design D2 is there, each with a title.
	 *
	 * @return void
	 */
	public function testThePropertiesOfDesignD2EachWithATitle(): void {
		$schema = self::schema();

		$this->assertSame(
			[
				'organisation',
				'provider',
				'kind',
				'enabled',
				'label',
				'idpMetadataUrl',
				'issuer',
				'spEntityId',
				'clientId',
				'clientAuth',
				'subjectMapping',
				'assuranceMapping',
				'pseudonymisation',
				'credentials',
				'yiviAttributes',
				'yiviTrust',
				'yiviBsnToConsumer',
			],
			array_keys($schema['properties'])
		);
		foreach ($schema['properties'] as $key => $property) {
			$this->assertNotSame('', (string)($property['title'] ?? ''), $key . ' has no title');
		}

		$this->assertSame(expected: ['digid', 'eherkenning', 'eidas', 'yivi'], actual: $schema['properties']['provider']['enum']);
		$this->assertSame(['saml-sp', 'oidc-rp'], $schema['properties']['kind']['enum']);
		$this->assertFalse($schema['properties']['enabled']['default']);
		$this->assertSame('hmac', $schema['properties']['pseudonymisation']['default']);
		$this->assertSame(['bsn', 'pseudonym', 'kvk', 'rsin', 'branch', 'eidasPersonIdentifier'], array_keys($schema['properties']['subjectMapping']['properties']));
	}//end testThePropertiesOfDesignD2EachWithATitle()

	/**
	 * Yivi's properties: the attribute list, the trust (low by default) and the BSN switch (off by default).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
	 */
	public function testTheYiviPropertiesAndTheirDefaults(): void {
		$schema = self::schema();
		$attributes = $schema['properties']['yiviAttributes'];

		$this->assertSame(expected: '1.1.0', actual: $schema['version']);
		$this->assertSame(expected: 'array', actual: $attributes['type']);
		$this->assertSame(expected: ['id', 'claim', 'label', 'carriesBsn'], actual: array_keys($attributes['items']['properties']));
		$this->assertSame(expected: ['id', 'claim'], actual: $attributes['items']['required']);
		$this->assertSame(expected: ['low', 'substantial'], actual: $schema['properties']['yiviTrust']['enum']);
		$this->assertSame(expected: 'low', actual: $schema['properties']['yiviTrust']['default']);
		$this->assertFalse(condition: $schema['properties']['yiviBsnToConsumer']['default']);
		$this->assertNotContains(needle: 'yiviTrust', haystack: $schema['required']);
	}//end testTheYiviPropertiesAndTheirDefaults()

	/**
	 * Credentials are references only: the five roles, plain strings, nothing else allowed.
	 *
	 * @return void
	 */
	public function testCredentialsHoldReferencesOnly(): void {
		$credentials = self::schema()['properties']['credentials'];

		$this->assertSame(['signing', 'decryption', 'clientSecret', 'pseudonymKeys', 'salt'], array_keys($credentials['properties']));
		$this->assertFalse($credentials['additionalProperties']);
		foreach ($credentials['properties'] as $role => $property) {
			$this->assertSame('string', $property['type'], $role);
		}
	}//end testCredentialsHoldReferencesOnly()

	/**
	 * Only administrators may read or write a registration.
	 *
	 * @return void
	 */
	public function testOnlyAdministrators(): void {
		$this->assertSame(
			['create' => ['admin'], 'read' => ['admin'], 'update' => ['admin'], 'delete' => ['admin']],
			self::schema()['authorization']
		);
	}//end testOnlyAdministrators()
}//end class
