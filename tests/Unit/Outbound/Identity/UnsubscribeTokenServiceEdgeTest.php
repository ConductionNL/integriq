<?php

/**
 * Unit tests for the edges of UnsubscribeTokenService: correctly signed tokens
 * whose payload is unreadable, a v3 token without a numeric expiry, an address
 * that cannot be encoded, and malformed short ids.
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

use OCA\Integriq\Db\UnsubscribeShortLinkMapperInterface;
use OCA\Integriq\Outbound\Identity\OptOutCategories;
use OCA\Integriq\Outbound\Identity\UnsubscribeTokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for tokens that are signed but cannot be read.
 *
 * @covers \OCA\Integriq\Outbound\Identity\UnsubscribeTokenService
 * @uses   \OCA\Integriq\Outbound\Identity\AppSecret
 */
class UnsubscribeTokenServiceEdgeTest extends TestCase {

	/**
	 * The stored signing secret.
	 *
	 * @var string
	 */
	private const SECRET = 'unsubscribe-unit-secret-0123456789';

	/**
	 * Short link mapper mock.
	 *
	 * @var UnsubscribeShortLinkMapperInterface&MockObject
	 */
	private UnsubscribeShortLinkMapperInterface&MockObject $shortLinks;

	/**
	 * The service under test.
	 *
	 * @var UnsubscribeTokenService
	 */
	private UnsubscribeTokenService $service;

	/**
	 * Build the service over a stored secret and a fixed clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === UnsubscribeTokenService::SIGNING_CONFIG_NAME ? self::SECRET : $default)
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$this->shortLinks = $this->createMock(UnsubscribeShortLinkMapperInterface::class);
		$this->service = new UnsubscribeTokenService(
			appConfig: $appConfig,
			random: $this->createMock(ISecureRandom::class),
			categories: $this->createMock(OptOutCategories::class),
			time: $time,
			shortLinks: $this->shortLinks,
			urls: $this->createMock(IURLGenerator::class),
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * Base64url-encode a raw payload the way tokens carry it.
	 *
	 * @param string $raw The raw payload.
	 *
	 * @return string The encoded payload.
	 */
	private function encode(string $raw): string {
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

	}//end encode()

	/**
	 * The signature over a payload with the stored secret.
	 *
	 * @param string $payload The signed part of the token.
	 *
	 * @return string The signature.
	 */
	private function sign(string $payload): string {
		return hash_hmac('sha256', $payload, self::SECRET);

	}//end sign()

	/**
	 * A correctly signed v1 token whose payload is not a JSON object is invalid.
	 *
	 * @return void
	 */
	public function testASignedV1TokenWithAnUnreadablePayloadIsInvalid(): void {
		$payload = $this->encode('"just a string"');

		$result = $this->service->inspect($payload . '.' . $this->sign($payload));

		$this->assertSame('invalid', $result['status']);
		$this->assertSame('', $result['format']);
		$this->assertSame('', $result['address']);

	}//end testASignedV1TokenWithAnUnreadablePayloadIsInvalid()

	/**
	 * A correctly signed v1 token with an object payload is valid and case-scoped.
	 *
	 * @return void
	 */
	public function testASignedV1TokenWithAnObjectPayloadIsValid(): void {
		$payload = $this->encode('{"a":"jan@example.org","c":"zaak/1"}');

		$result = $this->service->inspect($payload . '.' . $this->sign($payload));

		$this->assertSame('valid', $result['status']);
		$this->assertSame('v1', $result['format']);
		$this->assertSame('zaak/1', $result['caseRef']);
		$this->assertSame('case', $result['scope']);

	}//end testASignedV1TokenWithAnObjectPayloadIsValid()

	/**
	 * A correctly signed v3 token whose expiry is not an integer is invalid, not unexpiring.
	 *
	 * @return void
	 */
	public function testASignedV3TokenWithoutANumericExpiryIsInvalid(): void {
		$payload = $this->encode('{"a":"jan@example.org","s":"instance","e":"never"}');
		$signed = 'v3.' . $payload;

		$this->assertSame('invalid', $this->service->inspect($signed . '.' . $this->sign($signed))['status']);
		$this->assertNull($this->service->verify($signed . '.' . $this->sign($signed)));

	}//end testASignedV3TokenWithoutANumericExpiryIsInvalid()

	/**
	 * An address that cannot be JSON-encoded mints a token with an empty payload, which never verifies.
	 *
	 * @return void
	 */
	public function testAnAddressThatCannotBeEncodedMintsAnUnusableToken(): void {
		$token = $this->service->mintScoped("jan\xff@example.org", 'instance', 'email', '');

		$parts = explode('.', $token);
		$this->assertSame('v3', $parts[0]);
		$this->assertSame('', $parts[1]);
		$this->assertSame($this->sign('v3.'), $parts[2]);
		$this->assertSame('invalid', $this->service->inspect($token)['status']);

	}//end testAnAddressThatCannotBeEncodedMintsAnUnusableToken()

	/**
	 * A short id of the wrong shape is refused without reading the table.
	 *
	 * @return void
	 */
	public function testAMalformedShortIdIsRefusedWithoutALookup(): void {
		$this->shortLinks->expects($this->never())->method('findByShortId');

		$this->assertNull($this->service->resolveShort('short'));
		$this->assertNull($this->service->resolveShort('abcdefghi!'));
		$this->assertNull($this->service->resolveShort('abcdefghijk'));

	}//end testAMalformedShortIdIsRefusedWithoutALookup()

	/**
	 * A well-formed short id that is not in the table resolves to nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownShortIdResolvesToNothing(): void {
		$this->shortLinks->expects($this->once())->method('findByShortId')->with('Abc123XyZ0')->willReturn(null);

		$this->assertNull($this->service->resolveShort('Abc123XyZ0'));

	}//end testAnUnknownShortIdResolvesToNothing()

}//end class
