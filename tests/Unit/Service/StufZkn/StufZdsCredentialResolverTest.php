<?php

/**
 * Unit tests for StufZdsCredentialResolver.
 *
 * The WS-Security UsernameToken of a StUF-ZDS source: none when the source
 * names no user, the decrypted password when it does, and a refusal (never a
 * plaintext fallback) when the encrypted password is missing or cannot be
 * decrypted. Only ICrypto is a double.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\StufZkn
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

namespace OCA\Integriq\Tests\Unit\Service\StufZkn;

use OCA\Integriq\Exception\StufZknProviderException;
use OCA\Integriq\Service\StufZkn\StufZdsCredentialResolver;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Service\StufZkn\StufZdsCredentialResolver
 */
class StufZdsCredentialResolverTest extends TestCase {

	/**
	 * A source configuration with this WS-Security block.
	 *
	 * @param array $wsse The `authentication.wsse` block.
	 *
	 * @return array
	 */
	private static function configuration(array $wsse): array {
		return ['authentication' => ['mode' => 'wsse', 'wsse' => $wsse]];
	}//end configuration()

	public function testTheDecryptedPasswordIsReturnedWithTheTrimmedUser(): void {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->expects($this->once())->method('decrypt')->with('cipher')->willReturn('plain-password');

		$token = (new StufZdsCredentialResolver($crypto))->usernameToken(
			sourceConfiguration: self::configuration(['username' => ' zds ', 'encryptedPassword' => 'cipher'])
		);

		$this->assertSame(['username' => 'zds', 'password' => 'plain-password'], $token);
	}//end testTheDecryptedPasswordIsReturnedWithTheTrimmedUser()

	public function testASourceWithoutAUserHasNoTokenAndNothingIsDecrypted(): void {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->expects($this->never())->method('decrypt');
		$resolver = new StufZdsCredentialResolver($crypto);

		$this->assertNull($resolver->usernameToken(sourceConfiguration: []));
		$this->assertNull($resolver->usernameToken(sourceConfiguration: self::configuration(['username' => '   ', 'encryptedPassword' => 'cipher'])));
	}//end testASourceWithoutAUserHasNoTokenAndNothingIsDecrypted()

	public function testAUserWithoutAnEncryptedPasswordIsRefusedWithoutAPlaintextFallback(): void {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->expects($this->never())->method('decrypt');

		try {
			(new StufZdsCredentialResolver($crypto))->usernameToken(
				sourceConfiguration: self::configuration(['username' => 'zds', 'password' => 'plaintext'])
			);
			$this->fail('A user without an encrypted password must be refused');
		} catch (StufZknProviderException $exception) {
			$this->assertStringContainsString('authentication.wsse.encryptedPassword', $exception->getMessage());
		}
	}//end testAUserWithoutAnEncryptedPasswordIsRefusedWithoutAPlaintextFallback()

	public function testAPasswordThatCannotBeDecryptedIsRefusedWithTheCause(): void {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willThrowException(new RuntimeException('HMAC does not match.'));

		$this->expectException(StufZknProviderException::class);
		$this->expectExceptionMessage('The stored WS-Security password could not be decrypted: HMAC does not match.');

		(new StufZdsCredentialResolver($crypto))->usernameToken(
			sourceConfiguration: self::configuration(['username' => 'zds', 'encryptedPassword' => 'tampered'])
		);
	}//end testAPasswordThatCannotBeDecryptedIsRefusedWithTheCause()
}//end class
