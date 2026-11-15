<?php

/**
 * Reading a consuming app's registration from the stored broker configuration.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\IdpConsumer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The two stored forms of a consumer, and what is not a consumer at all.
 */
#[CoversClass(IdpConsumer::class)]
class IdpConsumerConfigTest extends TestCase {

	/**
	 * An entry that is neither the older string form nor an object is no consumer.
	 *
	 * @return void
	 */
	public function testAnEntryThatIsNeitherStringNorObjectIsNoConsumer(): void {
		foreach ([null, 42, true, 1.5] as $entry) {
			$this->assertNull(IdpConsumer::fromConfig(id: 'portaliq', entry: $entry));
		}
	}//end testAnEntryThatIsNeitherStringNorObjectIsNoConsumer()

	/**
	 * The older string form is enabled when non-empty and redeems with its inline secret only.
	 *
	 * @return void
	 */
	public function testTheOlderStringForm(): void {
		$consumer = IdpConsumer::fromConfig(id: 'portaliq', entry: 'inline-secret');

		$this->assertNotNull($consumer);
		$this->assertTrue($consumer->isEnabled());
		$this->assertTrue($consumer->isLegacy());
		$this->assertSame('inline-secret', $consumer->getLegacySecret());
		$this->assertSame([], $consumer->getReturnUrls());
		$this->assertFalse($consumer->mayReturnTo(returnUrl: 'https://portal.example.nl/cb'));

		$this->assertFalse(IdpConsumer::fromConfig(id: 'portaliq', entry: '')?->isEnabled());
	}//end testTheOlderStringForm()

	/**
	 * The object form keeps only non-empty string return addresses, and is
	 * enabled only by a literal true.
	 *
	 * @return void
	 */
	public function testTheObjectFormKeepsStringReturnAddressesOnly(): void {
		$consumer = IdpConsumer::fromConfig(
			id: 'portaliq',
			entry: [
				'enabled' => 'true',
				'returnUrls' => ['https://portal.example.nl/cb', '', 7, ['x']],
				'secretRef' => 'cred-1',
				'secretOrganisation' => 'gemeente-x',
			]
		);

		$this->assertNotNull($consumer);
		$this->assertFalse($consumer->isEnabled());
		$this->assertFalse($consumer->isLegacy());
		$this->assertSame(
			['enabled' => false, 'returnUrls' => ['https://portal.example.nl/cb'], 'secretRef' => 'cred-1', 'secretOrganisation' => 'gemeente-x'],
			$consumer->toConfig()
		);
	}//end testTheObjectFormKeepsStringReturnAddressesOnly()
}//end class
