<?php

/**
 * Tests for how a map under a sensitive key is masked by its keys, and for
 * bodies that mention an issued token without carrying one.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/http-call-engine/spec.md#requirement-req-006-calllog-request-response-redaction-before-persistence
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Security;

use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Service\Security\SensitiveValueMasker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A map whose keys are not setting names is a secret in itself and masked whole.
 */
#[CoversClass(SensitiveValueMasker::class)]
#[CoversClass(SensitiveFieldRegistry::class)]
class SensitiveValueMaskerMapKeysTest extends TestCase {

	private const P = SensitiveFieldRegistry::PLACEHOLDER;

	/**
	 * Mask a value found under a `secret` key, outside an export.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function maskUnderSecret(mixed $value): mixed {
		return (new SensitiveValueMasker(registry: new SensitiveFieldRegistry(), parentKey: 'secret', forExport: false))
			->mask(value: $value, extraExactNames: null);

	}//end maskUnderSecret()

	/**
	 * A key that is not a setting name makes the whole map the placeholder.
	 *
	 * @return void
	 */
	public function testAMapKeyedByNonSettingNamesIsMaskedWhole(): void {
		$this->assertSame(self::P, $this->maskUnderSecret(['tenant-1/key' => 'v']));
		$this->assertSame(self::P, $this->maskUnderSecret(['header' => 'X-Api-Key', '9lives' => 'v']));

	}//end testAMapKeyedByNonSettingNamesIsMaskedWhole()

	/**
	 * A map with integer keys that is not a list is masked whole.
	 *
	 * @return void
	 */
	public function testANonListMapWithIntegerKeysIsMaskedWhole(): void {
		$this->assertSame(self::P, $this->maskUnderSecret([3 => 'a', 9 => 'b']));

	}//end testANonListMapWithIntegerKeysIsMaskedWhole()

	/**
	 * A map keyed by setting names is masked per key, and a credential pointer stays readable.
	 *
	 * @return void
	 */
	public function testASettingsMapIsMaskedPerKey(): void {
		$this->assertSame(['header' => 'X-Api-Key', 'value' => self::P], $this->maskUnderSecret(['header' => 'X-Api-Key', 'value' => 's3cr3t']));
		$this->assertSame(['credentialRef' => ['credentialId' => 'c1']], $this->maskUnderSecret(['credentialRef' => ['credentialId' => 'c1']]));

	}//end testASettingsMapIsMaskedPerKey()

	/**
	 * A body that only mentions a token name in a value comes back byte-for-byte.
	 *
	 * @return void
	 */
	public function testABodyMentioningATokenWithoutCarryingOneIsUnchanged(): void {
		$body = '{"note":"access_token rotated","n":[1,2]}';

		$this->assertSame($body, (new SensitiveFieldRegistry())->redactIssuedTokensInJson(body: $body));

	}//end testABodyMentioningATokenWithoutCarryingOneIsUnchanged()

	/**
	 * A nested issued token is masked while empty objects and float zeros survive.
	 *
	 * @return void
	 */
	public function testANestedTokenIsMaskedAndTheRestKeptVerbatim(): void {
		$this->assertSame(
			'{"data":{"access_token":"' . self::P . '","e":{}},"x":1.0}',
			(new SensitiveFieldRegistry())->redactIssuedTokensInJson(body: '{"data":{"access_token":"abc","e":{}},"x":1.0}')
		);

	}//end testANestedTokenIsMaskedAndTheRestKeptVerbatim()
}//end class
