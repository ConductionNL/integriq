<?php

/**
 * Golden test for AuthenticationService::assertSafeTokenUrl(): which token
 * endpoint URLs are refused, with which message, and which pass.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/http-call-engine/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\AuthenticationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Twig\Loader\ArrayLoader;

/**
 * Pins the refused and allowed token URLs.
 */
class TokenUrlGuardGoldenTest extends TestCase {

	private const LOOPBACK = 'Token URL resolves to a loopback address - SSRF blocked';

	private const LINK_LOCAL = 'Token URL resolves to a link-local address - SSRF blocked';

	private const METADATA = 'Token URL resolves to a cloud metadata endpoint - SSRF blocked';

	private const PRIVATE = 'Token URL resolves to an RFC-1918 private address - SSRF blocked';

	private const UNROUTABLE = 'Token URL resolves to an unroutable address - SSRF blocked';

	/**
	 * What the guard answers for a URL: 'ok' or the refusal message.
	 *
	 * @param string $url The URL.
	 *
	 * @return string
	 */
	private function verdict(string $url): string {
		$method = new ReflectionMethod(AuthenticationService::class, 'assertSafeTokenUrl');
		try {
			$method->invoke(new AuthenticationService(new ArrayLoader()), $url);
		} catch (BadRequestException $exception) {
			return $exception->getMessage();
		}

		return 'ok';

	}//end verdict()

	/**
	 * Each URL and the verdict.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function urls(): array {
		return [
			'empty' => ['', 'Token URL must not be empty'],
			'http' => ['http://idp.example.org/token', 'Token URL scheme not allowed: only https is permitted (got "http")'],
			'no scheme' => ['idp.example.org/token', 'Token URL scheme not allowed: only https is permitted (got "")'],
			'upper HTTPS' => ['HTTPS://idp.example.org/token', 'ok'],
			'unparsable' => ['https:///token', 'Token URL scheme not allowed: only https is permitted (got "")'],
			'empty brackets host' => ['https://[]/token', 'Token URL does not contain a valid host'],
			'public' => ['https://idp.example.org/token', 'ok'],
			'public ip' => ['https://8.8.8.8/token', 'ok'],
			'localhost' => ['https://localhost/token', self::LOOPBACK],
			'LOCALHOST' => ['https://LOCALHOST:8443/token', self::LOOPBACK],
			'ipv6 loopback' => ['https://[::1]/token', self::LOOPBACK],
			'127' => ['https://127.0.0.1/token', self::LOOPBACK],
			'127 other' => ['https://127.10.0.1/token', self::LOOPBACK],
			'127 name' => ['https://127.example.org/token', self::LOOPBACK],
			'imds' => ['https://169.254.169.254/latest', self::LINK_LOCAL],
			'gcp metadata' => ['https://metadata.google.internal/token', self::METADATA],
			'metadata' => ['https://metadata/token', self::METADATA],
			'10' => ['https://10.1.2.3/token', self::PRIVATE],
			'192.168' => ['https://192.168.0.1/token', self::PRIVATE],
			'192.169' => ['https://192.169.0.1/token', 'ok'],
			'172.15' => ['https://172.15.0.1/token', 'ok'],
			'172.16' => ['https://172.16.0.1/token', self::PRIVATE],
			'172.31' => ['https://172.31.255.255/token', self::PRIVATE],
			'172.32' => ['https://172.32.0.1/token', 'ok'],
			'172 name' => ['https://172.example.org/token', 'ok'],
			'172.' => ['https://172./token', 'ok'],
			'0.0.0.0' => ['https://0.0.0.0/token', self::UNROUTABLE],
			'ipv6 any' => ['https://[::]/token', self::UNROUTABLE],
			'10 name' => ['https://10.example.org/token', self::PRIVATE],
			'userinfo' => ['https://user:pw@localhost/token', self::LOOPBACK],
		];

	}//end urls()

	/**
	 * Each URL gets the same verdict as before.
	 *
	 * @param string $url     The URL.
	 * @param string $verdict 'ok' or the refusal message.
	 *
	 * @return void
	 */
	#[DataProvider('urls')]
	public function testVerdictIsUnchanged(string $url, string $verdict): void {
		$this->assertSame($verdict, $this->verdict($url));

	}//end testVerdictIsUnchanged()
}//end class
