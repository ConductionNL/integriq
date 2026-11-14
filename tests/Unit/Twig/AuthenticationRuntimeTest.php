<?php

/**
 * The authentication Twig runtime and its loader: each token function hands
 * the source's `configuration.authentication` block to the matching fetch.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Twig
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/authentication-twig/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Twig;

use OCA\Integriq\Service\AuthenticationServiceInterface;
use OCA\Integriq\Twig\AuthenticationRuntime;
use OCA\Integriq\Twig\AuthenticationRuntimeLoader;
use OCA\Integriq\Twig\MappingRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AuthenticationRuntime and AuthenticationRuntimeLoader.
 *
 * @covers \OCA\Integriq\Twig\AuthenticationRuntime
 * @covers \OCA\Integriq\Twig\AuthenticationRuntimeLoader
 */
final class AuthenticationRuntimeTest extends TestCase {

	/**
	 * The authentication block every token function should pass on.
	 *
	 * @var array<string, string>
	 */
	private const AUTH = ['tokenUrl' => 'https://idp.example/token', 'clientId' => 'integriq'];

	/**
	 * The OAuth token comes from fetchOAuthTokens with the source's authentication block.
	 *
	 * @return void
	 */
	public function testOauthTokenPassesTheAuthenticationBlock(): void {
		$auth = $this->createMock(AuthenticationServiceInterface::class);
		$auth->expects($this->once())->method('fetchOAuthTokens')->with(self::AUTH)->willReturn('oauth-token');
		$auth->expects($this->never())->method('fetchDecosToken');
		$auth->expects($this->never())->method('fetchJWTToken');

		$token = (new AuthenticationRuntime($auth))->oauthToken(source: ['configuration' => ['authentication' => self::AUTH, 'other' => 'x']]);

		$this->assertSame('oauth-token', $token);
	}//end testOauthTokenPassesTheAuthenticationBlock()

	/**
	 * The Decos token comes from fetchDecosToken with the source's authentication block.
	 *
	 * @return void
	 */
	public function testDecosTokenPassesTheAuthenticationBlock(): void {
		$auth = $this->createMock(AuthenticationServiceInterface::class);
		$auth->expects($this->once())->method('fetchDecosToken')->with(self::AUTH)->willReturn('decos-token');
		$auth->expects($this->never())->method('fetchOAuthTokens');

		$this->assertSame('decos-token', (new AuthenticationRuntime($auth))->decosToken(source: ['configuration' => ['authentication' => self::AUTH]]));
	}//end testDecosTokenPassesTheAuthenticationBlock()

	/**
	 * The JWT comes from fetchJWTToken with the source's authentication block.
	 *
	 * @return void
	 */
	public function testJwtTokenPassesTheAuthenticationBlock(): void {
		$auth = $this->createMock(AuthenticationServiceInterface::class);
		$auth->expects($this->once())->method('fetchJWTToken')->with(self::AUTH)->willReturn('jwt-token');
		$auth->expects($this->never())->method('fetchOAuthTokens');

		$this->assertSame('jwt-token', (new AuthenticationRuntime($auth))->jwtToken(source: ['configuration' => ['authentication' => self::AUTH]]));
	}//end testJwtTokenPassesTheAuthenticationBlock()

	/**
	 * The loader builds the runtime over its service and declines any other class.
	 *
	 * @return void
	 */
	public function testTheLoaderOnlyBuildsTheAuthenticationRuntime(): void {
		$auth = $this->createMock(AuthenticationServiceInterface::class);
		$auth->expects($this->once())->method('fetchJWTToken')->with(self::AUTH)->willReturn('from-loader');
		$loader = new AuthenticationRuntimeLoader($auth);

		$runtime = $loader->load(AuthenticationRuntime::class);

		$this->assertInstanceOf(AuthenticationRuntime::class, $runtime);
		$this->assertSame('from-loader', $runtime->jwtToken(source: ['configuration' => ['authentication' => self::AUTH]]));
		$this->assertNull($loader->load(MappingRuntime::class));
		$this->assertNull($loader->load('Not\\A\\Class'));
	}//end testTheLoaderOnlyBuildsTheAuthenticationRuntime()
}//end class
