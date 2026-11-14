<?php

/**
 * Unit tests for AuthenticationService::fetchJWTToken(): the token is signed
 * with the configured key and algorithm, carries the rendered payload, and
 * names the certificate thumbprint when one is configured.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/authentication-twig/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\AuthenticationService;
use OCA\Integriq\Service\PemKeyFile;
use OCA\Integriq\Service\PemKeyFileRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Twig\Loader\ArrayLoader;

/**
 * Each token is decoded and its signature checked independently of the
 * library that made it: HMAC with the raw secret, RSA with the public key.
 */
#[CoversClass(AuthenticationService::class)]
#[UsesClass(PemKeyFile::class)]
#[UsesClass(PemKeyFileRequest::class)]
class AuthenticationServiceJwtTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var AuthenticationService
	 */
	private AuthenticationService $service;

	/**
	 * Build the service with an empty template loader.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new AuthenticationService(new ArrayLoader([]));
	}//end setUp()

	/**
	 * Decode one base64url part.
	 *
	 * @param string $part The part.
	 *
	 * @return string The raw bytes.
	 */
	private static function unbase64url(string $part): string {
		$padded = strtr($part, '-_', '+/');
		$padded .= str_repeat('=', ((4 - (strlen($padded) % 4)) % 4));
		return (string)base64_decode($padded, true);
	}//end unbase64url()

	/**
	 * Split a compact JWS into header, payload, signing input and signature.
	 *
	 * @param string $token The token.
	 *
	 * @return array{header: array<string,mixed>, payload: array<string,mixed>, input: string, signature: string}
	 */
	private static function parts(string $token): array {
		$parts = explode('.', $token);
		self::assertCount(3, $parts);

		return [
			'header' => json_decode(self::unbase64url($parts[0]), true),
			'payload' => json_decode(self::unbase64url($parts[1]), true),
			'input' => $parts[0] . '.' . $parts[1],
			'signature' => self::unbase64url($parts[2]),
		];
	}//end parts()

	/**
	 * An HS256 token is an HMAC-SHA256 over the raw secret, with the payload
	 * rendered through Twig from the configuration.
	 *
	 * @return void
	 */
	public function testAnHs256TokenIsSignedWithTheRawSecret(): void {
		$secret = "s3cr3t+/=\x01binary-and-long-enough-for-256-bits";
		$token = $this->service->fetchJWTToken(
			configuration: [
				'algorithm' => 'HS256',
				'secret' => $secret,
				'payload' => '{"iss": "{{ clientId }}", "aud": "zaken"}',
				'clientId' => 'integriq-test',
			]
		);

		$parts = self::parts(token: $token);
		$this->assertSame(['alg' => 'HS256', 'typ' => 'JWT'], $parts['header']);
		$this->assertSame(['iss' => 'integriq-test', 'aud' => 'zaken'], $parts['payload']);
		$this->assertTrue(hash_equals(hash_hmac('sha256', $parts['input'], $secret, true), $parts['signature']));
	}//end testAnHs256TokenIsSignedWithTheRawSecret()

	/**
	 * An HS512 token uses HMAC-SHA512.
	 *
	 * @return void
	 */
	public function testAnHs512TokenUsesSha512(): void {
		$secret = str_repeat('k', 64);
		$token  = $this->service->fetchJWTToken(configuration: ['algorithm' => 'HS512', 'secret' => $secret, 'payload' => '{"a": 1}']);

		$parts = self::parts(token: $token);
		$this->assertSame('HS512', $parts['header']['alg']);
		$this->assertTrue(hash_equals(hash_hmac('sha512', $parts['input'], $secret, true), $parts['signature']));
	}//end testAnHs512TokenUsesSha512()

	/**
	 * An RS256 token with a thumbprint carries `x5t` in its header and
	 * verifies against the public half of the configured key.
	 *
	 * @return void
	 */
	public function testAnRs256TokenNamesItsThumbprintAndVerifies(): void {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$this->assertNotFalse($key);
		openssl_pkey_export($key, $pem);
		$public = openssl_pkey_get_details($key)['key'];

		$token = $this->service->fetchJWTToken(
			configuration: [
				'algorithm' => 'RS256',
				'secret' => base64_encode($pem),
				'payload' => '{"sub": "client"}',
				'x5t' => 'dGh1bWJwcmludA',
			]
		);

		$parts = self::parts(token: $token);
		$this->assertSame(['alg' => 'RS256', 'typ' => 'JWT', 'x5t' => 'dGh1bWJwcmludA'], $parts['header']);
		$this->assertSame(['sub' => 'client'], $parts['payload']);
		$this->assertSame(1, openssl_verify($parts['input'], $parts['signature'], $public, OPENSSL_ALGO_SHA256));
	}//end testAnRs256TokenNamesItsThumbprintAndVerifies()

	/**
	 * A configuration missing a required parameter is refused, naming what is missing.
	 *
	 * @return void
	 */
	public function testMissingParametersAreNamed(): void {
		$this->expectException(BadRequestException::class);
		$this->expectExceptionMessage('Some required parameters are not set: [secret,algorithm]');

		$this->service->fetchJWTToken(configuration: ['payload' => '{}']);
	}//end testMissingParametersAreNamed()

	/**
	 * An algorithm the key generator does not know is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownAlgorithmIsRefused(): void {
		$this->expectException(BadRequestException::class);
		$this->expectExceptionMessage('Algorithm not supported by key generator');

		$this->service->fetchJWTToken(configuration: ['algorithm' => 'ES256', 'secret' => 'k', 'payload' => '{}']);
	}//end testAnUnknownAlgorithmIsRefused()
}//end class
