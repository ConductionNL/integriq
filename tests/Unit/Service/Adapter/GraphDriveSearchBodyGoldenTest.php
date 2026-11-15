<?php

/**
 * Characterisation test for the Graph drive search request body.
 *
 * Pins the exact `/search/query` body Microsoft365Adapter::search sends for
 * each shape of terms, dates, limit and region.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Adapter
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

namespace OCA\Integriq\Tests\Unit\Service\Adapter;

use OCA\Integriq\Service\Adapter\Saas\Microsoft365Adapter;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden record of the drive search bodies.
 */
class GraphDriveSearchBodyGoldenTest extends TestCase {

	/**
	 * The body sent for one search.
	 *
	 * @param string      $region The configured region, empty for the default.
	 * @param string      $terms  The terms.
	 * @param string|null $from   Start.
	 * @param string|null $to     End.
	 * @param int         $limit  The limit.
	 *
	 * @return mixed
	 */
	private function bodyFor(string $region, string $terms, ?string $from, ?string $to, int $limit): mixed {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === Microsoft365Adapter::SEARCH_REGION_KEY ? ($region === '' ? $default : $region) : 'cred-1')
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$broker = $this->createMock(CredentialBrokerService::class);
		$sent = null;
		$broker->method('request')->willReturnCallback(
			static function (...$args) use (&$sent): array {
				$sent = $args[5];
				return ['status' => 200, 'headers' => [], 'body' => '{"value":[]}'];
			}
		);

		$adapter = new Microsoft365Adapter(credentialBroker: $broker, appConfig: $config, logger: $this->createMock(LoggerInterface::class), l10n: $l10n);
		$adapter->search(terms: $terms, from: $from, to: $to, entityTypes: ['driveItem'], limit: $limit, userId: 'u');

		return ($sent === null ? null : json_decode((string)$sent, true));
	}//end bodyFor()

	/**
	 * The recorded bodies.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$record = [
			'both dates' => $this->bodyFor('', ' Stationsplein ', '2025-01-01', '2026-06-30', 10),
			'from only' => $this->bodyFor('', 'raad', '2025-01-01', null, 1),
			'to only, custom region' => $this->bodyFor('NAM', 'raad', null, '2026-06-30', 25),
			'malformed dates' => $this->bodyFor('', 'raad', '2025-1-1', '30-06-2026', 50),
			'datetime is not a date' => $this->bodyFor('', 'raad', '2025-01-01T00:00:00', '', 50),
			'zero limit' => $this->bodyFor('', 'raad', null, null, 0),
			'negative limit' => $this->bodyFor('', 'raad', null, null, -5),
			'huge limit' => $this->bodyFor('', 'raad', null, null, 100000),
			'blank terms' => $this->bodyFor('', '   ', '2025-01-01', null, 10),
		];

		$path = __DIR__ . '/../../../fixtures/adapter/graph-drive-search-bodies.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
