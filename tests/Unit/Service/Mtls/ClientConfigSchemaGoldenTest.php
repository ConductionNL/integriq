<?php

/**
 * Golden test pinning the configuration schema of the token-or-mTLS REST clients.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Mtls
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Mtls;

use GuzzleHttp\Client;
use OCA\Integriq\Service\Dso\DsoClient;
use OCA\Integriq\Service\Fsc\FscDirectoryClient;
use OCA\Integriq\Service\IwmoIjw\IStandardsClient;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Service\Mtls\MtlsTransportOptionsBuilder;
use OCA\Integriq\Service\Mtls\MtlsTransportService;
use OCA\Integriq\Service\StufZkn\StufZknClient;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins getConfigSchema() of every REST client that authenticates with a token or a client certificate,
 * key order included, against a JSON fixture.
 */
class ClientConfigSchemaGoldenTest extends TestCase {

	/**
	 * The clients under test, keyed by fixture name.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function clientProvider(): array {
		return [
			'dso' => [DsoClient::class],
			'fsc' => [FscDirectoryClient::class],
			'istandaarden' => [IStandardsClient::class],
			'stuf-zkn' => [StufZknClient::class],
		];

	}//end clientProvider()

	/**
	 * The schema a client reports is byte-for-byte the pinned fixture.
	 *
	 * @param string $clientClass The client class to build.
	 *
	 * @return void
	 */
	#[DataProvider('clientProvider')]
	public function testConfigSchemaMatchesFixture(string $clientClass): void {
		$crypto = $this->createMock(ICrypto::class);
		$logger = $this->createMock(LoggerInterface::class);
		$client = new $clientClass(
			new Client(),
			$crypto,
			$this->createMock(IL10N::class),
			$logger,
			new MtlsConfigResolver($crypto),
			new MtlsTransportService(new MtlsTransportOptionsBuilder(), $logger)
		);

		$name = (string)$this->dataName();
		$expected = (string)file_get_contents(__DIR__ . '/../../../fixtures/client-config-schema/' . $name . '.json');

		$this->assertSame(
			$expected,
			json_encode($client->getConfigSchema(), (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n"
		);

	}//end testConfigSchemaMatchesFixture()
}//end class
