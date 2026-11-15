<?php

/**
 * Unit tests for AuthenticationService's token request refusals.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/authentication-twig/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\AuthenticationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Twig\Loader\ArrayLoader;

/**
 * A token request that is incomplete, unsupported or unsafe is refused before any call.
 */
#[CoversClass(AuthenticationService::class)]
class AuthenticationServiceTokenRequestTest extends TestCase {

	/**
	 * Configurations refused by fetchOAuthTokens, with the message expected.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function refusedOAuthConfigurations(): array {
		return [
			'no grant type' => [
				['tokenUrl' => 'https://idp.example.org/token'],
				'Grant type not set, cannot request token',
			],
			'no token url' => [
				['grant_type' => 'client_credentials'],
				'Token URL not set, cannot request token',
			],
			'plain http' => [
				['grant_type' => 'client_credentials', 'tokenUrl' => 'http://idp.example.org/token'],
				'Token URL scheme not allowed: only https is permitted (got "http")',
			],
			'loopback host' => [
				['grant_type' => 'password', 'tokenUrl' => 'https://127.0.0.1/token'],
				'Token URL resolves to a loopback address - SSRF blocked',
			],
			'private 172 range' => [
				['grant_type' => 'password', 'tokenUrl' => 'https://172.20.1.1/token'],
				'Token URL resolves to an RFC-1918 private address - SSRF blocked',
			],
			'unsupported grant' => [
				['grant_type' => 'authorization_code', 'tokenUrl' => 'https://idp.example.org/token'],
				'Grant type not supported',
			],
		];
	}//end refusedOAuthConfigurations()

	/**
	 * fetchOAuthTokens refuses the configuration with the expected message.
	 *
	 * @param array<string,mixed> $configuration The configuration.
	 * @param string $message The expected message.
	 *
	 * @return void
	 */
	#[DataProvider('refusedOAuthConfigurations')]
	public function testFetchOAuthTokensRefuses(array $configuration, string $message): void {
		$this->expectException(BadRequestException::class);
		$this->expectExceptionMessage($message);

		(new AuthenticationService(new ArrayLoader([])))->fetchOAuthTokens($configuration);
	}//end testFetchOAuthTokensRefuses()

	/**
	 * A 172 address outside 16..31 is not mistaken for a private one.
	 *
	 * @return void
	 */
	public function testAPublic172AddressPassesTheGuard(): void {
		$this->expectException(BadRequestException::class);
		$this->expectExceptionMessage('Grant type not supported');

		(new AuthenticationService(new ArrayLoader([])))->fetchOAuthTokens(
			['grant_type' => 'implicit', 'tokenUrl' => 'https://172.32.0.1/token']
		);
	}//end testAPublic172AddressPassesTheGuard()

	/**
	 * fetchDecosToken refuses an unsafe token endpoint before calling it.
	 *
	 * @return void
	 */
	public function testFetchDecosTokenRefusesAnUnsafeUrl(): void {
		$this->expectException(BadRequestException::class);
		$this->expectExceptionMessage('Token URL resolves to a cloud metadata endpoint - SSRF blocked');

		(new AuthenticationService(new ArrayLoader([])))->fetchDecosToken(
			['tokenUrl' => 'https://metadata.google.internal/token', 'tokenLocation' => 'token']
		);
	}//end testFetchDecosTokenRefusesAnUnsafeUrl()
}//end class
