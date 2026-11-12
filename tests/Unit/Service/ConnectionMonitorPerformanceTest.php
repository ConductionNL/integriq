<?php

/**
 * Characterisation tests for ConnectionMonitorService's performance block:
 * error kinds, the empty period, key order and paging of the call log.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Integriq\Service\ConnectionCertificateReader;
use OCA\Integriq\Service\ConnectionMonitorService;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the performance block of a connection report.
 */
final class ConnectionMonitorPerformanceTest extends TestCase {

	private const NOW = '2026-10-07T11:00:00+02:00';

	private const SOURCE = 'b1b2c3d4-0000-4000-8000-00000000b4b0';

	/**
	 * The call log rows, newest first.
	 *
	 * @var list<ObjectEntity>
	 */
	private array $calls = [];

	/**
	 * Every call-log page offset read.
	 *
	 * @var list<int>
	 */
	private array $offsets = [];

	/**
	 * The performance block for the stored calls.
	 *
	 * @return array<string,mixed>
	 */
	private function performance(): array {
		$source = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'BRP', 'slug' => 'brp', 'configuration' => []], self::SOURCE);
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('find')->willReturn($source);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				if ($config['filters']['schema'] !== 'call_log') {
					return [];
				}

				$this->offsets[] = $config['offset'];
				return ['results' => array_slice($this->calls, $config['offset'], $config['limit'])];
			}
		);
		$mtls = $this->createMock(MtlsConfigResolver::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $params = []): string => vsprintf($text, $params));
		$l10n->method('n')->willReturnCallback(
			fn (string $one, string $many, int $count): string => str_replace('%n', (string)$count, ($count === 1 ? $one : $many))
		);
		$service = new ConnectionMonitorService(
			$objects,
			new ConnectionCertificateReader($mtls, $this->createMock(IEventDispatcher::class), $l10n, $this->createMock(LoggerInterface::class)),
			$l10n
		);

		return $service->report(sourceRef: self::SOURCE, now: new DateTimeImmutable(self::NOW))['performance'];

	}//end performance()

	/**
	 * One call-log row.
	 *
	 * @param array  $body The call fields.
	 * @param string $at   When it was created.
	 *
	 * @return ObjectEntity
	 */
	private function call(array $body, string $at = '2026-10-07T10:00:00+02:00'): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity($this, array_merge(['source' => self::SOURCE, 'created' => $at], $body), 'c');

	}//end call()

	/**
	 * Every error kind, a success range, timings in either field, and the
	 * full key order of the block.
	 *
	 * @return void
	 */
	public function testEveryErrorKindIsCountedAndTheBlockKeepsItsShape(): void {
		$this->calls = [
			$this->call(['statusCode' => 200, 'durationMs' => 1200.6]),
			$this->call(['statusCode' => 399, 'response' => ['responseTime' => '15']]),
			$this->call(['statusCode' => 404, 'durationMs' => 'n/a']),
			$this->call(['statusCode' => 503]),
			$this->call(['statusCode' => 500, 'durationMs' => 3000]),
			$this->call(['statusCode' => 0, 'statusMessage' => 'SSL certificate problem']),
			$this->call(['statusCode' => 199, 'statusMessage' => 'TLS handshake']),
			$this->call(['statusMessage' => 'cURL error 28']),
			$this->call(['statusCode' => 200], 'not a date'),
		];

		$this->assertSame(
			[
				'calls'          => 8,
				'errors'         => 6,
				'errorsByKind'   => ['timeout' => 1, 'client' => 1, 'server' => 2, 'tls' => 2],
				'errorSummary'   => '1 time-outs, 1 client errors, 2 server errors, 2 TLS errors',
				'slowestMs'      => 3000,
				'slowestSeconds' => 3.0,
				'slowCount'      => 2,
				'slowSummary'    => '2 calls slower than 1 s',
				'averageMs'      => 1405,
			],
			$this->performance()
		);

	}//end testEveryErrorKindIsCountedAndTheBlockKeepsItsShape()

	/**
	 * A period without calls reports zeros, no average and no summary.
	 *
	 * @return void
	 */
	public function testAnEmptyPeriodReportsNothing(): void {
		$this->calls = [$this->call(['statusCode' => 500], '2026-10-01T10:00:00+02:00')];

		$this->assertSame(
			[
				'calls'          => 0,
				'errors'         => 0,
				'errorsByKind'   => ['timeout' => 0, 'client' => 0, 'server' => 0, 'tls' => 0],
				'errorSummary'   => '',
				'slowestMs'      => 0,
				'slowestSeconds' => 0.0,
				'slowCount'      => 0,
				'slowSummary'    => '0 calls slower than 1 s',
				'averageMs'      => null,
			],
			$this->performance()
		);

	}//end testAnEmptyPeriodReportsNothing()

	/**
	 * Full pages are read on until a page holds an older call or is short.
	 *
	 * @return void
	 */
	public function testTheCallLogIsPagedUntilAnOlderOrShortPage(): void {
		$this->calls = array_fill(0, 1000, $this->call(['statusCode' => 200]));
		$this->calls[] = $this->call(['statusCode' => 200]);
		$this->assertSame(1001, $this->performance()['calls']);
		$this->assertSame([0, 500, 1000], $this->offsets);

		$this->offsets = [];
		$this->calls = array_fill(0, 500, $this->call(['statusCode' => 200]));
		$this->calls[499] = $this->call(['statusCode' => 200], '2026-10-01T10:00:00+02:00');
		$this->calls[] = $this->call(['statusCode' => 200]);
		$this->assertSame(499, $this->performance()['calls']);
		$this->assertSame([0], $this->offsets);

	}//end testTheCallLogIsPagedUntilAnOlderOrShortPage()

}//end class
