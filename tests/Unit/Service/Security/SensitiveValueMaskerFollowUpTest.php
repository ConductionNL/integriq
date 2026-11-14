<?php

/**
 * Export masking of API-key maps, templates with literals, and the kept auth settings.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Security;

use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Service\Security\SensitiveValueMasker;
use PHPUnit\Framework\TestCase;

/**
 * Masking cases for credential maps, templates and auth settings.
 *
 * @covers \OCA\Integriq\Service\Security\SensitiveValueMasker
 * @covers \OCA\Integriq\Service\Security\SensitiveFieldRegistry
 * @uses   \OCA\Integriq\Service\Security\StructuredSecretRedactor
 */
class SensitiveValueMaskerFollowUpTest extends TestCase {

	private const P = SensitiveFieldRegistry::PLACEHOLDER;

	/**
	 * Redact one configuration the way an export does.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return array<string, mixed>
	 */
	private function redact(array $configuration): array {
		return (new SensitiveFieldRegistry())->redactConfiguration(data: $configuration);
	}//end redact()

	/**
	 * An API-key map keyed by the key is masked whole, under authentication and at the top level.
	 *
	 * @return void
	 */
	public function testAnApiKeyMapIsMaskedWhole(): void {
		$out = $this->redact(
			[
				'authentication' => ['type' => 'apikey', 'header' => 'X-Api-Key', 'keys' => ['k-0123456789secret' => 'alice', 'k-other' => 'bob']],
				'keys' => ['top-0123456789secret' => 'carol'],
			]
		);

		$this->assertSame(['type' => 'apikey', 'header' => 'X-Api-Key', 'keys' => self::P], $out['authentication']);
		$this->assertSame(self::P, $out['keys']);
		$this->assertStringNotContainsString('secret', json_encode($out));
	}//end testAnApiKeyMapIsMaskedWhole()

	/**
	 * An API-key map whose keys are letters only is masked whole too, in exports and in logs.
	 *
	 * @return void
	 */
	public function testALettersOnlyApiKeyMapIsMaskedWhole(): void {
		$config = [
			'authentication' => ['type' => 'apikey', 'keys' => ['municipalityPartnerKey' => 'alice'], 'apiKeys' => ['Zaak_Koppeling' => 'bob']],
			'keys' => ['TopLevelPartnerKey' => 'carol'],
			'settings' => ['keys' => ['NestedPartnerKey' => 'dave']],
		];
		$registry = new SensitiveFieldRegistry();
		foreach ([$registry->redactConfiguration(data: $config), $registry->redactArray(data: $config, extraExactNames: null)] as $out) {
			$this->assertSame(self::P, $out['authentication']['keys']);
			$this->assertSame(self::P, $out['authentication']['apiKeys']);
			$this->assertSame(self::P, $out['keys']);
			$this->assertSame(self::P, $out['settings']['keys']);
			$this->assertStringNotContainsString('PartnerKey', json_encode($out));
			$this->assertStringNotContainsString('Koppeling', json_encode($out));
		}
	}//end testALettersOnlyApiKeyMapIsMaskedWhole()

	/**
	 * A nested settings map made of named settings keeps its structure.
	 *
	 * @return void
	 */
	public function testANestedSettingsMapKeepsItsStructure(): void {
		$out = $this->redact(['authentication' => ['oauth' => ['grant_type' => 'client_credentials', 'client_secret' => 's3cret']]]);

		$this->assertSame(['oauth' => ['grant_type' => 'client_credentials', 'client_secret' => self::P]], $out['authentication']);
	}//end testANestedSettingsMapKeepsItsStructure()

	/**
	 * Only a bare-variable template stays readable; a template with a literal is masked.
	 *
	 * @return void
	 */
	public function testOnlyABareVariableTemplateStaysReadable(): void {
		$out = $this->redact(
			[
				'password' => '{{ source.pw }}',
				'secret' => "{{ src.pw|default('lit-secret') }}",
				'token' => '{{ "lit-secret" }}',
				'apikey' => '{{source.configuration.key}}',
			]
		);

		$this->assertSame('{{ source.pw }}', $out['password']);
		$this->assertSame(self::P, $out['secret']);
		$this->assertSame(self::P, $out['token']);
		$this->assertSame('{{source.configuration.key}}', $out['apikey']);
	}//end testOnlyABareVariableTemplateStaysReadable()

	/**
	 * The non-secret call parameters of an auth configuration survive export.
	 *
	 * @return void
	 */
	public function testNonSecretAuthParametersSurviveExport(): void {
		$auth = [
			'tokenUrl' => 'https://idp.example/token',
			'scope' => 'read write',
			'audience' => 'api://x',
			'publicKey' => 'pub-material',
			'keyId' => 'kid-1',
			'signatureAlgorithm' => 'RS256',
			'bodyKey' => 'api_key',
			'algorithm' => 'HS256',
			'x5t' => 'thumb',
			'tokenLocation' => 'header',
			'passwordType' => 'PasswordText',
			'username' => 'svc-user',
		];

		$out = $this->redact(['authentication' => $auth]);

		$this->assertSame($auth, $out['authentication']);
	}//end testNonSecretAuthParametersSurviveExport()

	/**
	 * The public call parameters stay readable at the top of a configuration too; their maps do not.
	 *
	 * @return void
	 */
	public function testPublicParametersStayReadableAtTheTop(): void {
		$flat = ['tokenUrl' => 'https://idp.example/token', 'publicKey' => 'PUB', 'keyId' => 'kid-1', 'signatureAlgorithm' => 'RS256', 'bodyKey' => 'k', 'username' => 'svc'];
		$out  = $this->redact($flat + ['password' => 'pw', 'publicKey2' => 'x']);

		$this->assertSame($flat, array_intersect_key($out, $flat));
		$this->assertSame(self::P, $out['password']);
		$this->assertSame(self::P, $out['publicKey2']);
		$this->assertSame(self::P, $this->redact(['keyId' => ['nested' => 'v']])['keyId']['nested']);
	}//end testPublicParametersStayReadableAtTheTop()

	/**
	 * A readable setting whose value does not fit its shape is masked under a credential map.
	 *
	 * @return void
	 */
	public function testSettingsThatDoNotFitTheirShapeAreMasked(): void {
		$out = $this->redact(
			[
				'authentication' => [
					'type' => 'bearer',
					'id' => 'id-secret',
					'uuid' => 'uuid-secret',
					'header' => 'Bearer tok-secret',
					'users' => ['alice' => 'pw-secret'],
					'groups' => ['admin', ['nested' => 'x']],
					'oauth' => ['id' => 'nested-secret'],
				],
				'auth' => ['id' => 'auth-id-secret'],
			]
		);

		$this->assertSame(
			[
				'authentication' => [
					'type' => 'bearer',
					'id' => self::P,
					'uuid' => self::P,
					'header' => self::P,
					'users' => ['alice' => self::P],
					'groups' => ['admin', ['nested' => self::P]],
					'oauth' => ['id' => self::P],
				],
				'auth' => ['id' => self::P],
			],
			$out
		);
	}//end testSettingsThatDoNotFitTheirShapeAreMasked()

	/**
	 * A setting that fits its shape stays readable, and an id outside a credential map too.
	 *
	 * @return void
	 */
	public function testSettingsThatFitTheirShapeStayReadable(): void {
		$auth = ['type' => 'apikey', 'users' => ['alice'], 'groups' => [], 'header' => 'X-Hub-Signature-256'];

		$this->assertSame($auth, $this->redact(['authentication' => $auth])['authentication']);
		$this->assertSame(['id' => 'k-1', 'uuid' => 'u-1', 'value' => self::P], $this->redact(['apiKeys' => ['id' => 'k-1', 'uuid' => 'u-1', 'value' => 'v']])['apiKeys']);
		$this->assertSame(['id' => 'k-2'], $this->redact(['some.dotted.apikey' => ['id' => 'k-2']])['some.dotted.apikey']);
		$this->assertSame(['id' => self::P], $this->redact(['some.dotted.authentication' => ['id' => 'k-3']])['some.dotted.authentication']);
	}//end testSettingsThatFitTheirShapeStayReadable()

	/**
	 * The strategy strings stay readable as a bare word, and nothing else does.
	 *
	 * @return void
	 */
	public function testStrategyStringsStayReadableOnlyAsABareWord(): void {
		$kept = ['authentication' => 'basic_auth', 'authorizationPassthroughMethod' => 'header'];
		$this->assertSame($kept, $this->redact($kept));

		foreach (['abc123', 'Bearer tok', str_repeat('a', 33), ''] as $value) {
			$this->assertSame(self::P, $this->redact(['authentication' => $value])['authentication'], $value);
			$this->assertSame(self::P, $this->redact(['authorizationPassthroughMethod' => $value])['authorizationPassthroughMethod'], $value);
		}

		$this->assertSame(['authorizationPassthroughMethod' => 'query'], $this->redact(['authentication' => ['authorizationPassthroughMethod' => 'query']])['authentication']);
	}//end testStrategyStringsStayReadableOnlyAsABareWord()

	/**
	 * Logs and traces read redactArray(): the export-only parameters stay masked there.
	 *
	 * @return void
	 */
	public function testLogsAndTracesKeepMaskingExportParameters(): void {
		$out = (new SensitiveFieldRegistry())->redactArray(
			[
				'username' => 'alice',
				'keyId' => 'k',
				'authentication' => 'body',
				'auth' => ['username' => 'bob', 'scope' => 'read', 'type' => 'basic'],
			]
		);

		$this->assertSame(
			['username' => self::P, 'keyId' => self::P, 'authentication' => self::P, 'auth' => ['username' => self::P, 'scope' => self::P, 'type' => 'basic']],
			$out
		);
	}//end testLogsAndTracesKeepMaskingExportParameters()

	/**
	 * Every write-only leaf of the source schema is masked under authentication and listed as readable nowhere.
	 *
	 * @return void
	 */
	public function testEveryWriteOnlyLeafIsMasked(): void {
		$leaves = SensitiveValueMasker::writeOnlyLeaves();
		$this->assertContains('client_secret', $leaves);
		$this->assertContains('mtls', $leaves);
		$this->assertContains('encryptedToken', $leaves);

		foreach (['NON_SECRET_SETTING_KEYS', 'EXPORT_SETTING_KEYS', 'PUBLIC_PARAMETER_KEYS', 'STRATEGY_KEYS'] as $list) {
			$readable = (new \ReflectionClassConstant(SensitiveValueMasker::class, $list))->getValue();
			$this->assertSame([], array_values(array_intersect($leaves, $readable)), $list);
		}

		$auth = array_fill_keys($leaves, 'leaked-value');
		$out  = $this->redact(['authentication' => $auth]);

		$this->assertSame(array_fill_keys($leaves, self::P), $out['authentication']);
	}//end testEveryWriteOnlyLeafIsMasked()
}//end class
