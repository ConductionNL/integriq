<?php

/**
 * Golden test pinning how the Forms and Tables OCS clients classify a call's outcome.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Tables
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/nextcloud-tables-connector/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Tables;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Forms\FormsOcsClient;
use OCA\Integriq\Service\Tables\TablesOcsClient;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * For both clients: the exception class, message and status code of every refused outcome,
 * the transport-failure log line, and the bodies that decode to nothing.
 */
class OcsClientDispatchGoldenTest extends TestCase {

	/** @var array<int, array> Log lines: [level, message, context]. */
	private array $logs = [];

	/** @var array<int, array> Calls: [endpoint, method, config]. */
	private array $calls = [];

	/**
	 * Build a client of the given class whose CallService answers as given.
	 *
	 * @param string            $clientClass The client class.
	 * @param array|\Throwable  $answer      The call log's `response`, or what the call throws.
	 *
	 * @return FormsOcsClient|TablesOcsClient
	 */
	private function client(string $clientClass, array|\Throwable $answer): object {
		$this->logs = [];
		$this->calls = [];
		$callService = $this->createMock(CallService::class);
		$callService->method('call')->willReturnCallback(
			function (...$arguments) use ($answer) {
				$this->calls[] = [($arguments['endpoint'] ?? $arguments[1]), ($arguments['method'] ?? $arguments[2]), ($arguments['config'] ?? $arguments[3] ?? [])];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				$callLog = $this->createMock(ObjectEntity::class);
				$callLog->method('getUuid')->willReturn('log-7');
				$callLog->method('getObject')->willReturn(['response' => $answer]);
				return $callLog;
			}
		);
		$logs = &$this->logs;
		$logger = new class($logs) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param array $logs The shared log line list.
			 */
			public function __construct(private array &$logs) {
			}

			/**
			 * Record one log line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->logs[] = [$level, (string)$message, $context];
			}
		};

		return new $clientClass($callService, $logger);

	}//end client()

	/**
	 * Run a call and describe what it threw.
	 *
	 * @param callable $call The call.
	 *
	 * @return array [class, message, code], or ['none'].
	 */
	private function thrown(callable $call): array {
		try {
			$call();
		} catch (\Exception $exception) {
			return [$exception::class, $exception->getMessage(), $exception->getCode()];
		}

		return ['none'];

	}//end thrown()

	/**
	 * The two clients, each with a read call, its endpoint and its exception namespace prefix.
	 *
	 * @return array<string, array>
	 */
	private function clients(): array {
		$source = ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://nc.example.test'], 'source-uuid-1');

		return [
			'Forms' => [
				FormsOcsClient::class,
				fn (object $client) => $client->listForms(source: $source),
				'/index.php/apps/forms/api/v3/forms',
			],
			'Tables' => [
				TablesOcsClient::class,
				fn (object $client) => $client->listTables(source: $source),
				'/index.php/apps/tables/api/1/tables',
			],
		];

	}//end clients()

	/**
	 * Every refused outcome, for both clients.
	 *
	 * @return void
	 */
	public function testRefusedOutcomes(): void {
		foreach ($this->clients() as $label => [$clientClass, $call, $endpoint]) {
			$exception = 'OCA\\Integriq\\Exception\\' . $label;
			foreach ([401, 403] as $status) {
				$this->assertSame(
					[$exception . 'PermissionDeniedException', $label . ' API denied GET ' . $endpoint . ' (HTTP ' . $status . ')', $status],
					$this->thrown(fn () => $call($this->client($clientClass, ['statusCode' => $status, 'body' => '[]']))),
					$label . ' ' . $status
				);
			}

			$this->assertSame(
				[$exception . 'NotFoundException', $label . ' API resource not found: GET ' . $endpoint, 404],
				$this->thrown(fn () => $call($this->client($clientClass, ['statusCode' => 404])))
			);
			foreach ([500, 302, 199, 0] as $status) {
				$this->assertSame(
					[$exception . 'UpstreamException', $label . ' API returned HTTP ' . $status . ' for GET ' . $endpoint . ' (see CallLog log-7)', 502],
					$this->thrown(fn () => $call($this->client($clientClass, ['statusCode' => $status, 'body' => '[]']))),
					$label . ' ' . $status
				);
			}

			$this->assertSame(
				[$exception . 'UpstreamException', 'Failed to reach the ' . $label . ' API (GET ' . $endpoint . '): connection refused', 502],
				$this->thrown(fn () => $call($this->client($clientClass, new \OCP\DB\Exception('connection refused'))))
			);
			$this->assertSame(
				[['warning', $label . 'OcsClient: transport failure calling ' . $label . ' API', ['endpoint' => $endpoint, 'method' => 'GET', 'error' => 'connection refused']]],
				$this->logs
			);

			$this->assertSame(['RuntimeException', 'other', 0], $this->thrown(fn () => $call($this->client($clientClass, new \RuntimeException('other')))));
		}//end foreach

	}//end testRefusedOutcomes()

	/**
	 * Bodies that decode to nothing: empty, not JSON, a JSON scalar; and a 2xx other than 200.
	 *
	 * @return void
	 */
	public function testBodiesThatDecodeToNothing(): void {
		foreach ($this->clients() as $label => [$clientClass, $call]) {
			foreach (['', 'not json', '"text"', '12'] as $body) {
				$this->assertSame([], $call($this->client($clientClass, ['statusCode' => 200, 'body' => $body])), $label . ' ' . $body);
			}

			$this->assertSame([], $call($this->client($clientClass, ['statusCode' => 204])), $label . ' 204');
			$this->assertSame([], $this->logs);
			$this->assertSame('GET', $this->calls[0][1]);
		}

	}//end testBodiesThatDecodeToNothing()
}//end class
