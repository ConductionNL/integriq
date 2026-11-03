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
	 * a credentialRef pointer passes unchanged (REQ-010).
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
			['type' => self::MASK, 'credentialRef' => ['credentialId' => 'c-1'], 'login' => ['name' => self::MASK]],
			$result['authentication']
		);
		$this->assertSame(5, $result['timeout']);
	}//end testEveryLeafUnderASensitiveKeyIsMasked()

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
				'sessionTimeout' => 30,
				'session' => 'cookie-store',
			]
		);

		$this->assertSame(self::MASK, $result['X-Session-Id']);
		$this->assertSame(['X-Session-Id' => self::MASK, 'Session' => self::MASK, 'Accept' => 'json'], $result['headers']);
		$this->assertSame(['headers' => ['x-session' => [self::MASK]]], $result['nested']);
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
			]
		);

		$this->assertSame('https://user:' . self::MASK . '@host.example/p?token=' . self::MASK . '&q={{ id }}', $result['url']);
		$this->assertSame('/api/x?api_key=' . self::MASK . '&page=2', $result['path']);
		$this->assertSame('/x?Api%2DKey=' . self::MASK . '#frag', $result['encoded']);
		$this->assertSame('https://host.example/p?q=1&keyboard', $result['plain']);
		$this->assertSame('what? a=b', $result['text']);
		$this->assertSame(['https://a.example/?secret=' . self::MASK], $result['list']);
		$this->assertSame('/x?page=1&p%61ssword=' . self::MASK . '&q=2', $result['later']);
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
}//end class
