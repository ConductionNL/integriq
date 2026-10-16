<?php

/**
 * StUF WS-Security inbound verifier test: the replay window and the cache fall-backs (aud-f3).
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
 *
 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-provider-requests-are-authenticated-against-the-source
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\StufZkn;

use OCA\Integriq\Service\StufZkn\StufWsseInboundVerifier;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;

/**
 * @covers \OCA\Integriq\Service\StufZkn\StufWsseInboundVerifier
 */
class StufWsseInboundVerifierTest extends TestCase {

	/**
	 * The source's decrypted token.
	 *
	 * @var array{username:string,password:string}
	 */
	private const TOKEN = ['username' => 'zsh', 'password' => 'secret'];

	/**
	 * An envelope carrying a UsernameToken.
	 *
	 * @param string $extra Elements added after the password (Nonce, Created).
	 *
	 * @return SimpleXMLElement The envelope.
	 */
	private static function envelope(string $extra = ''): SimpleXMLElement {
		return new SimpleXMLElement(
			'<Envelope xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">'
			. '<Header><wsse:Security><wsse:UsernameToken>'
			. '<wsse:Username>zsh</wsse:Username><wsse:Password>secret</wsse:Password>' . $extra
			. '</wsse:UsernameToken></wsse:Security></Header></Envelope>'
		);
	}//end envelope()

	/**
	 * A Nonce and a fresh Created.
	 *
	 * @param string $nonce The nonce.
	 *
	 * @return string The elements.
	 */
	private static function fresh(string $nonce): string {
		return '<wsse:Nonce>' . $nonce . '</wsse:Nonce><Created>' . gmdate('Y-m-d\TH:i:s\Z') . '</Created>';
	}//end fresh()

	/**
	 * A verifier over a plain ICache (no atomic add) that remembers what is set.
	 *
	 * @param bool            $available Whether the factory reports a distributed cache.
	 * @param LoggerInterface $logger    The logger.
	 *
	 * @return StufWsseInboundVerifier
	 */
	private function verifier(bool $available, LoggerInterface $logger): StufWsseInboundVerifier {
		$seen  = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('hasKey')->willReturnCallback(static function (string $key) use (&$seen): bool {
				return isset($seen[$key]);
			});
		$cache->method('set')->willReturnCallback(
			static function (string $key) use (&$seen): bool {
				$seen[$key] = true;
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$factory->method('isAvailable')->willReturn($available);

		return new StufWsseInboundVerifier($factory, $logger);
	}//end verifier()

	/**
	 * On a plain cache a nonce is remembered by check-then-set: its first use passes, a repeat does not.
	 *
	 * @return void
	 */
	public function testAPlainCacheRemembersANonceByCheckThenSet(): void {
		$verifier = $this->verifier(available: true, logger: $this->createMock(LoggerInterface::class));
		$request  = self::envelope(self::fresh('n-1'));

		$this->assertTrue($verifier->verify($request, self::TOKEN, [], 'zsh'));
		$this->assertFalse($verifier->verify($request, self::TOKEN, [], 'zsh'), 'the repeat is a replay');
		$this->assertTrue($verifier->verify(self::envelope(self::fresh('n-2')), self::TOKEN, [], 'zsh'));
	}//end testAPlainCacheRemembersANonceByCheckThenSet()

	/**
	 * With no distributed cache a repeat cannot be told from a first use: the token is accepted only when the source does not require a nonce, and the gap is logged.
	 *
	 * @return void
	 */
	public function testWithoutASharedCacheTheNonceIsNotTrusted(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning')->with($this->stringContains('nonce not checked'));
		$verifier = $this->verifier(available: false, logger: $logger);
		$request  = self::envelope(self::fresh('n-1'));

		$this->assertTrue($verifier->verify($request, self::TOKEN, [], 'zsh'), 'a source that does not require a nonce still gets in');
		$this->assertFalse(
			$verifier->verify($request, self::TOKEN, ['authentication' => ['wsse' => ['requireNonce' => true]]], 'zsh'),
			'a source that requires one cannot be served without a cache'
		);
	}//end testWithoutASharedCacheTheNonceIsNotTrusted()

	/**
	 * A source without credentials never authenticates, and a wrong user name or password is refused.
	 *
	 * @return void
	 */
	public function testTheCredentialsMustMatchTheSources(): void {
		$verifier = $this->verifier(available: true, logger: $this->createMock(LoggerInterface::class));

		$this->assertTrue($verifier->verify(self::envelope(), self::TOKEN, [], 'zsh'), 'a token without a nonce is accepted unless the source requires one');
		$this->assertFalse($verifier->verify(self::envelope(), ['username' => '', 'password' => ''], [], 'zsh'));
		$this->assertFalse($verifier->verify(self::envelope(), ['username' => 'zsh', 'password' => 'other'], [], 'zsh'));
		$this->assertFalse($verifier->verify(self::envelope(), ['username' => 'other', 'password' => 'secret'], [], 'zsh'));
		$this->assertFalse(
			$verifier->verify(self::envelope(), self::TOKEN, ['authentication' => ['wsse' => ['requireNonce' => true]]], 'zsh'),
			'a source that requires a nonce refuses a plain token'
		);
	}//end testTheCredentialsMustMatchTheSources()
}//end class
