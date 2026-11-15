<?php

/**
 * Unit tests for the edges of RecipientKey: keys that are already hashed,
 * digital-post addresses that are not a BSN, and channel-less addresses that
 * look like a phone number but do not normalise.
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

use OCA\Integriq\Outbound\Identity\RecipientKey;
use OCA\Integriq\Service\Sms\PhoneNumberNormaliserInterface;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for key normalisation and hashing edges.
 *
 * @covers \OCA\Integriq\Outbound\Identity\RecipientKey
 * @uses   \OCA\Integriq\Outbound\Identity\AppSecret
 */
class RecipientKeyEdgeTest extends TestCase {

	/**
	 * The stored hashing secret.
	 *
	 * @var string
	 */
	private const SECRET = 'recipient-key-unit-secret';

	/**
	 * Phone number normaliser mock.
	 *
	 * @var PhoneNumberNormaliserInterface&MockObject
	 */
	private PhoneNumberNormaliserInterface&MockObject $phoneNumbers;

	/**
	 * The key under test.
	 *
	 * @var RecipientKey
	 */
	private RecipientKey $key;

	/**
	 * Build the key over a stored secret.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === RecipientKey::HASHING_CONFIG_NAME ? self::SECRET : $default)
		);
		$random = $this->createMock(ISecureRandom::class);
		$random->expects($this->never())->method('generate');
		$this->phoneNumbers = $this->createMock(PhoneNumberNormaliserInterface::class);
		$this->key = new RecipientKey($appConfig, $random, $this->phoneNumbers);

	}//end setUp()

	/**
	 * An empty key, a BSN key and an already hashed key are returned as they are.
	 *
	 * @return void
	 */
	public function testAKeyThatIsEmptyOrAlreadyHashedIsKept(): void {
		$this->assertSame('', $this->key->hashKey('   '));
		$this->assertSame('bsn:abc123', $this->key->hashKey(' bsn:abc123 '));
		$this->assertSame('h:def456', $this->key->hashKey('h:def456'));

	}//end testAKeyThatIsEmptyOrAlreadyHashedIsKept()

	/**
	 * A plain key is hashed with the stored secret, trimmed first.
	 *
	 * @return void
	 */
	public function testAPlainKeyIsHashedWithTheStoredSecret(): void {
		$this->assertSame(
			'h:' . hash_hmac('sha256', 'jan@example.org', self::SECRET),
			$this->key->hashKey(' jan@example.org ')
		);

	}//end testAPlainKeyIsHashedWithTheStoredSecret()

	/**
	 * A digital-post address that is not nine digits is lowercased, not hashed.
	 *
	 * @return void
	 */
	public function testADigitalPostAddressThatIsNotABsnIsLowercased(): void {
		$this->assertSame('mijnoverheid:ab-12', $this->key->normalise(' Digital-Post ', 'MijnOverheid:AB-12'));
		$this->assertSame(
			'bsn:' . hash_hmac('sha256', '123456782', self::SECRET),
			$this->key->normalise('digital-post', '123456782')
		);

	}//end testADigitalPostAddressThatIsNotABsnIsLowercased()

	/**
	 * Without a known channel, a phone-shaped address that does not normalise is kept lowercased.
	 *
	 * @return void
	 */
	public function testAPhoneShapedAddressThatDoesNotNormaliseIsKept(): void {
		$this->phoneNumbers->expects($this->once())->method('toE164')->with('+99 (0) 12-34-56')->willReturn(null);

		$this->assertSame('+99 (0) 12-34-56', $this->key->normalise('', '+99 (0) 12-34-56'));

	}//end testAPhoneShapedAddressThatDoesNotNormaliseIsKept()

	/**
	 * Without a known channel, a phone-shaped address that normalises becomes E.164.
	 *
	 * @return void
	 */
	public function testAPhoneShapedAddressThatNormalisesBecomesE164(): void {
		$this->phoneNumbers->expects($this->once())->method('toE164')->with('06 1234 5678')->willReturn('+31612345678');

		$this->assertSame('+31612345678', $this->key->normalise('fax', '06 1234 5678'));

	}//end testAPhoneShapedAddressThatNormalisesBecomesE164()

	/**
	 * An email channel address without an `@` has no key; anything else unknown is lowercased.
	 *
	 * @return void
	 */
	public function testAnEmailWithoutAnAtHasNoKey(): void {
		$this->phoneNumbers->expects($this->never())->method('toE164');

		$this->assertNull($this->key->normalise('email', 'jan.example.org'));
		$this->assertNull($this->key->normalise('email', '   '));
		$this->assertSame('handle-jan', $this->key->normalise('', 'Handle-Jan'));

	}//end testAnEmailWithoutAnAtHasNoKey()

}//end class
