<?php

/**
 * Unit tests for the outbound URL egress guard.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/events-async-api-products/design.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Security;

use OCA\Integriq\Exception\EgressRefusedException;
use OCA\Integriq\Service\Security\EgressGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rules of integriq#2212: https only, and never an internal or metadata
 * address, judged on every address the host resolves to.
 *
 * @spec openspec/changes/events-async-api-products/design.md
 */
class EgressGuardTest extends TestCase {

	/**
	 * A guard whose DNS answers come from a fixed table, so no test resolves a
	 * real name.
	 *
	 * @param array<string, array<int, string>> $dns Host to addresses.
	 * @param string $allowedHosts The `egress_allowed_hosts` app config value.
	 *
	 * @return EgressGuard
	 */
	private function guard(array $dns = [], string $allowedHosts = ''): EgressGuard {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => ($key === EgressGuard::ALLOWED_HOSTS_KEY ? $allowedHosts : $default)
		);

		return new class($appConfig, $dns) extends EgressGuard {
			/**
			 * Constructor.
			 *
			 * @param IAppConfig $appConfig The app config.
			 * @param array<string, array<int, string>> $dns Host to addresses.
			 */
			public function __construct(IAppConfig $appConfig, private array $dns) {
				parent::__construct(appConfig: $appConfig);
			}

			/**
			 * Answer from the fixed table; a literal is its own answer.
			 *
			 * @param string $host The host.
			 *
			 * @return array<int, string>
			 */
			protected function addressesOf(string $host): array {
				if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
					return [$host];
				}

				return ($this->dns[$host] ?? []);
			}
		};
	}//end guard()

	/**
	 * URLs that must be refused with no allowlist.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function refusedUrls(): array {
		return [
			'metadata over http' => ['http://169.254.169.254/latest/meta-data/'],
			'metadata over https' => ['https://169.254.169.254/latest/meta-data/'],
			'metadata by name' => ['https://metadata.google.internal/computeMetadata/v1/'],
			'plain http' => ['http://receiver.example.org/hook'],
			'loopback' => ['https://127.0.0.1:8080/'],
			'localhost name' => ['https://localhost/'],
			'private 10/8' => ['https://10.1.2.3/hook'],
			'private 172.16/12' => ['https://172.20.0.5/hook'],
			'private 192.168/16' => ['https://192.168.1.10/hook'],
			'carrier-grade nat' => ['https://100.64.0.1/hook'],
			'unspecified' => ['https://0.0.0.0/'],
			'ipv6 loopback' => ['https://[::1]/'],
			'ipv6 unique local' => ['https://[fd12:3456::1]/'],
			'ipv6 link-local' => ['https://[fe80::1]/'],
			'ipv4-mapped metadata' => ['https://[::ffff:169.254.169.254]/'],
			'a name that resolves inside' => ['https://intranet.example.org/hook'],
			'other scheme' => ['file:///etc/passwd'],
			'no host' => ['https:///hook'],
		];
	}//end refusedUrls()

	/**
	 * Every internal, metadata or non-https URL is refused.
	 *
	 * @param string $url The URL.
	 *
	 * @return void
	 */
	#[DataProvider('refusedUrls')]
	public function testRefuses(string $url): void {
		$guard = $this->guard(
			dns: [
				'localhost' => ['127.0.0.1'],
				'intranet.example.org' => ['93.184.216.34', '10.0.0.7'],
				'receiver.example.org' => ['93.184.216.34'],
			]
		);

		$this->expectException(EgressRefusedException::class);
		$guard->assertAllowed(url: $url);
	}//end testRefuses()

	/**
	 * An https URL on a public host passes, and so does an unresolvable one
	 * (nothing to judge; the call fails on its own).
	 *
	 * @return void
	 */
	public function testAllowsPublicHttps(): void {
		$guard = $this->guard(dns: ['receiver.example.org' => ['93.184.216.34', '2606:2800:220:1::1']]);

		$guard->assertAllowed(url: 'https://receiver.example.org/hook');
		$guard->assertAllowed(url: 'https://unresolvable.example/hook');
		$guard->assertAllowed(url: 'https://93.184.216.34:8443/hook');

		$this->addToAssertionCount(3);
	}//end testAllowsPublicHttps()

	/**
	 * An allowlisted internal receiver may use http and a private address.
	 *
	 * @return void
	 */
	public function testAllowlistedHostMayBeInternal(): void {
		$guard = $this->guard(dns: ['n8n' => ['172.18.0.4']], allowedHosts: 'n8n, other.internal');

		$guard->assertAllowed(url: 'http://n8n:5678/webhook/abc');

		$this->addToAssertionCount(1);
	}//end testAllowlistedHostMayBeInternal()

	/**
	 * The allowlist never opens a link-local or metadata address.
	 *
	 * @return void
	 */
	public function testAllowlistNeverOpensMetadata(): void {
		$guard = $this->guard(dns: ['sneaky.internal' => ['169.254.169.254']], allowedHosts: 'sneaky.internal,169.254.169.254');

		foreach (['http://sneaky.internal/', 'http://169.254.169.254/'] as $url) {
			try {
				$guard->assertAllowed(url: $url);
				$this->fail($url . ' was allowed');
			} catch (EgressRefusedException $exception) {
				$this->assertStringContainsString('link-local or metadata', $exception->getMessage());
			}
		}
	}//end testAllowlistNeverOpensMetadata()

	/**
	 * Without app config (a guard built outside the container) nothing is allowlisted.
	 *
	 * @return void
	 */
	public function testNoAppConfigMeansNoAllowlist(): void {
		$this->expectException(EgressRefusedException::class);
		(new EgressGuard())->assertAllowed(url: 'http://10.0.0.1/');
	}//end testNoAppConfigMeansNoAllowlist()
}//end class
