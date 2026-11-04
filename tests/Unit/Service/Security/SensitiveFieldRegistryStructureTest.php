<?php

/**
 * Unit tests for the structural redaction of SensitiveFieldRegistry.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Security;

use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Secrets that sit under a sensitive key, in header maps and lists, and
 * inside URLs are masked; everything else is left byte for byte.
 *
 * @covers \OCA\Integriq\Service\Security\SensitiveFieldRegistry
 * @covers \OCA\Integriq\Service\Security\StructuredSecretRedactor
 * @covers \OCA\Integriq\Service\Security\SensitiveValueMasker
 */
class SensitiveFieldRegistryStructureTest extends TestCase {

	private const MASK = SensitiveFieldRegistry::PLACEHOLDER;

	/**
	 * The registry under test.
	 *
	 * @var SensitiveFieldRegistry
	 */
	private SensitiveFieldRegistry $registry;

	/**
	 * Set up the registry.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->registry = new SensitiveFieldRegistry();
	}//end setUp()

	/**
	 * Every scalar under a sensitive key is masked, whatever its own name;
	 * a credentialRef pointer (REQ-010) and a non-secret setting pass unchanged.
	 *
	 * @return void
	 */
	public function testEveryLeafUnderASensitiveKeyIsMasked(): void {
		$result = $this->registry->redactArray(
			data: [
				'auth' => ['user' => 'u', 'pass' => 'p', 'value' => 'v', 'nested' => ['x' => 1, 'list' => ['a']]],
				'authentication' => ['type' => 'basic', 'credentialRef' => ['credentialId' => 'c-1'], 'login' => ['name' => 'n']],
				'timeout' => 5,
			]
		);

		$this->assertSame(
			['user' => self::MASK, 'pass' => self::MASK, 'value' => self::MASK, 'nested' => ['x' => self::MASK, 'list' => [self::MASK]]],
			$result['auth']
		);
		$this->assertSame(
			['type' => 'basic', 'credentialRef' => ['credentialId' => 'c-1'], 'login' => ['name' => self::MASK]],
			$result['authentication']
		);
		$this->assertSame(5, $result['timeout']);
	}//end testEveryLeafUnderASensitiveKeyIsMasked()

	/**
	 * Under a sensitive map, the settings that say how a credential is used stay
	 * readable; the credential itself does not.
	 *
	 * @return void
	 */
	public function testNonSecretSettingsUnderASensitiveMapStayReadable(): void {
		$result = $this->registry->redactArray(
			data: [
				'authentication' => ['type' => 'apiKey', 'users' => ['alice'], 'groups' => ['admin'], 'header' => 'X-Api-Key', 'keys' => [['k-1' => 'alice']]],
				'webhook_signature' => ['scheme' => 'hmac-sha256', 'header' => 'X-Signature', 'toleranceSeconds' => 300, 'secret' => 's'],
				'auth' => ['scheme' => 'oauth2', 'mode' => 'client_credentials', 'grant_type' => 'client_credentials', 'client_id' => 'cid', 'client_secret' => 'cs'],
				'apiKeys' => ['id' => 'k-uuid', 'uuid' => 'u-1', 'value' => 'v'],
				'authorization' => ['users' => ['bob', ['password' => 'p']]],
				'bearerAuth' => ['credentialRef' => 'raw-ref'],
			]
		);

		$this->assertSame(
			['type' => 'apiKey', 'users' => ['alice'], 'groups' => ['admin'], 'header' => 'X-Api-Key', 'keys' => [self::MASK]],
			$result['authentication']
		);
		$this->assertSame(['scheme' => 'hmac-sha256', 'header' => 'X-Signature', 'toleranceSeconds' => 300, 'secret' => self::MASK], $result['webhook_signature']);
		$this->assertSame(
			['scheme' => 'oauth2', 'mode' => 'client_credentials', 'grant_type' => 'client_credentials', 'client_id' => 'cid', 'client_secret' => self::MASK],
			$result['auth']
		);
		$this->assertSame(['id' => 'k-uuid', 'uuid' => 'u-1', 'value' => self::MASK], $result['apiKeys']);
		$this->assertSame(['users' => ['bob', ['password' => self::MASK]]], $result['authorization']);
		$this->assertSame(['credentialRef' => self::MASK], $result['bearerAuth']);
	}//end testNonSecretSettingsUnderASensitiveMapStayReadable()

	/**
	 * A credentialRef keeps its pointer keys but not a secret placed inside it.
	 *
	 * @return void
	 */
	public function testACredentialRefKeepsOnlyItsPointer(): void {
		$result = $this->registry->redactArray(
			data: ['authentication' => ['credentialRef' => ['credentialName' => 'n', 'password' => 'p']]]
		);

		$this->assertSame(['credentialName' => 'n', 'password' => self::MASK], $result['authentication']['credentialRef']);
	}//end testACredentialRefKeepsOnlyItsPointer()

	/**
	 * Session headers are masked in a header map and as a header-shaped key;
	 * a field merely named after a session is not.
	 *
	 * @return void
	 */
	public function testSessionHeadersAreMaskedWhereverAHeaderStands(): void {
		$result = $this->registry->redactConfiguration(
			data: [
				'X-Session-Id' => 'top',
				'headers' => ['X-Session-Id' => 'h', 'Session' => 'raw', 'Accept' => 'json'],
				'nested' => ['headers' => ['x-session' => ['s']]],
				'upper' => ['Headers' => ['Session' => 'u', 'Accept' => 'json']],
				'sessionTimeout' => 30,
				'session' => 'cookie-store',
			]
		);

		$this->assertSame(self::MASK, $result['X-Session-Id']);
		$this->assertSame(['X-Session-Id' => self::MASK, 'Session' => self::MASK, 'Accept' => 'json'], $result['headers']);
		$this->assertSame(['headers' => ['x-session' => [self::MASK]]], $result['nested']);
		$this->assertSame(['Headers' => ['Session' => self::MASK, 'Accept' => 'json']], $result['upper']);
		$this->assertSame(30, $result['sessionTimeout']);
		$this->assertSame('cookie-store', $result['session']);
	}//end testSessionHeadersAreMaskedWhereverAHeaderStands()

	/**
	 * In a name/value header list, the value of a secret header is masked.
	 *
	 * @return void
	 */
	public function testNameValueHeaderListsMaskSecretHeaderValues(): void {
		$result = $this->registry->redactConfiguration(
			data: [
				'headers' => [
					['name' => 'Authorization', 'value' => 'Bearer z'],
					['name' => 'X-Session-Token', 'value' => 'st'],
					['name' => 'Accept', 'value' => 'json'],
					['name' => 'Cookie'],
					'plain',
				],
			]
		);

		$this->assertSame(
			[
				['name' => 'Authorization', 'value' => self::MASK],
				['name' => 'X-Session-Token', 'value' => self::MASK],
				['name' => 'Accept', 'value' => 'json'],
				['name' => 'Cookie'],
				'plain',
			],
			$result['headers']
		);
	}//end testNameValueHeaderListsMaskSecretHeaderValues()

	/**
	 * A URL's password and its secret query values are masked; the rest of the
	 * text, placeholders included, is left as it was.
	 *
	 * @return void
	 */
	public function testSecretsInsideUrlsAreMasked(): void {
		$result = $this->registry->redactConfiguration(
			data: [
				'url' => 'https://user:pw@host.example/p?token=T&q={{ id }}',
				'path' => '/api/x?api_key=AK&page=2',
				'encoded' => '/x?Api%2DKey=AK#frag',
				'plain' => 'https://host.example/p?q=1&keyboard',
				'text' => 'what? a=b',
				'list' => ['https://a.example/?secret=s'],
				'later' => '/x?page=1&p%61ssword=pw&q=2',
				'templates' => 'https://host.example/p?token={{ source.token }}&api_key=%7B%7Bkey%7D%7D&secret=s',
				'templateLogin' => 'https://user:{{pw}}@host.example/',
				'inText' => 'call https://user:pw@host.example/a then ftp://u2:p2@files.example/',
				'semicolons' => '/x?page=1;token=T;q=2',
				'partialTemplate' => '/x?secret=abc{{ x }}&token=%20{{t}}%20',
			]
		);

		$this->assertSame('https://user:' . self::MASK . '@host.example/p?token=' . self::MASK . '&q={{ id }}', $result['url']);
		$this->assertSame('/api/x?api_key=' . self::MASK . '&page=2', $result['path']);
		$this->assertSame('/x?Api%2DKey=' . self::MASK . '#frag', $result['encoded']);
		$this->assertSame('https://host.example/p?q=1&keyboard', $result['plain']);
		$this->assertSame('what? a=b', $result['text']);
		$this->assertSame(['https://a.example/?secret=' . self::MASK], $result['list']);
		$this->assertSame('/x?page=1&p%61ssword=' . self::MASK . '&q=2', $result['later']);
		$this->assertSame('https://host.example/p?token={{ source.token }}&api_key=%7B%7Bkey%7D%7D&secret=' . self::MASK, $result['templates']);
		$this->assertSame('https://user:{{pw}}@host.example/', $result['templateLogin']);
		$this->assertSame('call https://user:' . self::MASK . '@host.example/a then ftp://u2:' . self::MASK . '@files.example/', $result['inText']);
		$this->assertSame('/x?page=1;token=' . self::MASK . ';q=2', $result['semicolons']);
		$this->assertSame('/x?secret=' . self::MASK . '&token=%20{{t}}%20', $result['partialTemplate']);
	}//end testSecretsInsideUrlsAreMasked()

	/**
	 * Values that are not text, and keys that are not names, are left alone.
	 *
	 * @return void
	 */
	public function testNonTextValuesAreLeftAlone(): void {
		$data = ['n' => 1, 'b' => false, 'z' => null, 0 => 'https://a.example/', 'empty' => []];

		$this->assertSame($data, $this->registry->redactConfiguration(data: $data));
	}//end testNonTextValuesAreLeftAlone()

	/**
	 * Header sets under any key that ends in `headers`, dotted header paths and
	 * header pairs in any casing are masked by header name.
	 *
	 * @return void
	 */
	public function testHeaderSetsAreFoundByShapeNotByOneKey(): void {
		$result = $this->registry->redactConfiguration(
			data: [
				'headers.X-Session-Id' => 'dotted',
				'headers.Session' => 'dotted-dashless',
				'cfg.headers.Accept' => 'json',
				'session.timeout' => 30,
				'extraHeaders' => [['Name' => 'Authorization', 'Value' => 'Bearer z'], ['key' => 'X-Session', 'value' => 's'], ['NAME' => 'Accept', 'VALUE' => 'json']],
				'defaultHeaders' => ['Session' => 'd', 'XSessionId' => 'x', 'Accept' => 'json'],
			]
		);

		$this->assertSame(self::MASK, $result['headers.X-Session-Id']);
		$this->assertSame(self::MASK, $result['headers.Session']);
		$this->assertSame('json', $result['cfg.headers.Accept']);
		$this->assertSame(30, $result['session.timeout']);
		$this->assertSame(
			// A field called `key` is masked by its own name as well.
			[['Name' => 'Authorization', 'Value' => self::MASK], ['key' => self::MASK, 'value' => self::MASK], ['NAME' => 'Accept', 'VALUE' => 'json']],
			$result['extraHeaders']
		);
		$this->assertSame(['Session' => self::MASK, 'XSessionId' => self::MASK, 'Accept' => 'json'], $result['defaultHeaders']);
	}//end testHeaderSetsAreFoundByShapeNotByOneKey()

	/**
	 * A credential entry in a list is masked whole, its key included; a pure
	 * template under a sensitive key is kept.
	 *
	 * @return void
	 */
	public function testCredentialEntriesAreMaskedWholeAndTemplatesKept(): void {
		$result = $this->registry->redactArray(
			data: [
				'keys' => [['live-api-key' => 'alice'], 'raw', ['nested', ['x' => 1]]],
				'token' => '{{ source.token }}',
				'password' => 'Bearer {{ x }}',
			]
		);

		$this->assertSame([self::MASK, self::MASK, [self::MASK, self::MASK]], $result['keys']);
		$this->assertSame('{{ source.token }}', $result['token']);
		$this->assertSame(self::MASK, $result['password']);
	}//end testCredentialEntriesAreMaskedWholeAndTemplatesKept()
}//end class
