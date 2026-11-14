<?php

/**
 * Characterisation test for EgressGuard::assertAllowed.
 *
 * Pins every allowed and refused outbound URL, with resolution replaced by a
 * fixed map so the record does not depend on DNS.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Security;

use OCA\Integriq\Service\Security\EgressGuard;
use OCA\Integriq\Exception\EgressRefusedException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Golden record of the egress guard.
 */
class EgressGuardGoldenTest extends TestCase {

	/**
	 * The recorded verdicts.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(' Intern.Example.Test , ,dev.example.test');
		$names = [
			'public.example.test' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
			'intern.example.test' => ['10.1.2.3'],
			'dev.example.test' => ['169.254.169.254'],
			'mixed.example.test' => ['93.184.216.34', '192.168.1.10'],
			'mapped.example.test' => ['::ffff:127.0.0.1'],
			'unresolved.example.test' => [],
			'junk.example.test' => ['not-an-ip', '93.184.216.34'],
		];
		$guard = new class($config, $names) extends EgressGuard {
			/**
			 * @param IAppConfig                     $config The config.
			 * @param array<string, array<int,string>> $names  Host to addresses.
			 */
			public function __construct(IAppConfig $config, private readonly array $names) {
				parent::__construct(appConfig: $config);
			}

			/**
			 * @param string $host The host.
			 *
			 * @return array<int, string>
			 */
			protected function addressesOf(string $host): array {
				if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
					return [$host];
				}

				return ($this->names[$host] ?? []);
			}
		};
		$plain = new class($names) extends EgressGuard {
			/**
			 * @param array<string, array<int,string>> $names Host to addresses.
			 */
			public function __construct(private readonly array $names) {
				parent::__construct();
			}

			/**
			 * @param string $host The host.
			 *
			 * @return array<int, string>
			 */
			protected function addressesOf(string $host): array {
				return ($this->names[$host] ?? [$host]);
			}
		};

		$urls = [
			'https://public.example.test/api', 'http://public.example.test/api', 'ftp://public.example.test', '//public.example.test/x',
			'https://intern.example.test', 'http://intern.example.test', 'http://INTERN.example.test', 'https://dev.example.test',
			'https://mixed.example.test', 'https://mapped.example.test', 'https://unresolved.example.test', 'https://junk.example.test',
			'https://metadata.google.internal/x', 'https://metadata./x', 'https://Instance-Data/x', 'not a url', 'https:///path',
			'https://127.0.0.1', 'https://10.255.255.255', 'https://11.0.0.1', 'https://100.64.0.1', 'https://100.128.0.1',
			'https://172.16.0.1', 'https://172.31.255.255', 'https://172.32.0.1', 'https://192.0.0.8', 'https://192.0.2.1',
			'https://198.18.0.1', 'https://198.19.255.255', 'https://198.20.0.1', 'https://224.0.0.1', 'https://239.255.255.255',
			'https://240.0.0.1', 'https://255.255.255.255', 'https://0.0.0.0', 'https://169.254.1.1', 'https://8.8.8.8',
			'https://[::1]/', 'https://[::]/', 'https://[fe80::1]/', 'https://[febf::1]/', 'https://[fec0::1]/', 'https://[fd00:ec2::254]/',
			'https://[fd00::1]/', 'https://[fc00::1]/', 'https://[fe00::1]/', 'https://[ff02::1]/', 'https://[2001:db8::1]/',
			'https://[::ffff:10.0.0.1]/', 'https://[::ffff:8.8.8.8]/', 'https://[::ffff:169.254.169.254]/',
		];
		$record = [];
		foreach (['with allow list' => $guard, 'without config' => $plain] as $set => $subject) {
			foreach ($urls as $url) {
				try {
					$subject->assertAllowed($url);
					$record[$set][$url] = 'allowed';
				} catch (EgressRefusedException $e) {
					$record[$set][$url] = 'refused: ' . $e->getMessage();
				}
			}
		}

		$path = __DIR__ . '/../../../fixtures/security/egress-guard.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
