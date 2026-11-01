<?php

/**
 * Golden characterization of the BankfeedSyncService sync sweep.
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
 *
 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateInterval;
use DateTime;
use OCA\Integriq\Exception\Psd2ConsentRevokedException;
use OCA\Integriq\Exception\Psd2ProviderException;
use OCA\Integriq\Service\BankfeedSyncService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\Psd2\LogPsd2AggregatorProvider;
use OCA\Integriq\Service\Psd2\RestPsd2AggregatorProvider;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sweeps connections in every consent state through syncAll() and records
 * each aggregator pull, batch, event, watermark and log against a golden
 * file. The sweep runs as the system: no user or request value reaches it,
 * so the consent state of each connection alone decides whether the
 * aggregator is called. Regenerate the file on the code it must describe
 * with BANKFEED_SWEEP_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\BankfeedSyncService
 * @uses   \OCA\Integriq\Service\BankfeedSyncRun
 */
class BankfeedSyncServiceSweepGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/bankfeed/sync-sweep.json';

	/**
	 * Everything the sweep pulled, saved, emitted and logged, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $trace = [];

	/**
	 * The connections of one sweep, by case name.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function sweeps(): array {
		$accounts = [
			['aggregatorAccountId' => 'acc-1', 'iban' => 'NL01BANK0000000001'],
			['iban' => 'NL02BANK0000000002'],
			['aggregatorAccountId' => '', 'iban' => 'NL03'],
			['aggregatorAccountId' => 'acc-2'],
		];
		$base = static fn (string $id, array $extra = []): array => array_merge(
			[
				'connectionId' => $id,
				'aggregatorSourceSlug' => 'psd2-log',
				'lifecycleState' => 'active',
				'consentReference' => 'ref-' . $id,
				'accounts' => $accounts,
			],
			$extra
		);
		$soon = (new DateTime())->add(new DateInterval('P2D'))->format('c');
		$later = (new DateTime())->add(new DateInterval('P60D'))->format('c');
		$past = (new DateTime())->sub(new DateInterval('P1D'))->format('c');

		return [
			'nothing' => [],
			'not-active' => [$base('c-pending', ['lifecycleState' => 'pending']), $base('c-none', ['lifecycleState' => null]), $base('c-revoked', ['lifecycleState' => 'revoked'])],
			'watermark' => [$base('c-mark', ['lastSyncAt' => '2025-10-01T00:00:00+00:00', 'consentGrantedAt' => '2025-09-01T00:00:00+00:00'])],
			'granted' => [$base('c-grant', ['lastSyncAt' => null, 'consentGrantedAt' => '2025-09-01T00:00:00+00:00'])],
			'backfill' => [$base('c-fresh')],
			'rest-provider' => [$base('c-rest', ['aggregatorSourceSlug' => 'psd2-rest', 'lastSyncAt' => '2025-10-01T00:00:00+00:00'])],
			'expired' => [$base('c-expired', ['consentExpiresAt' => $past])],
			'expiring' => [$base('c-expiring', ['consentExpiresAt' => $soon, 'lastSyncAt' => '2025-10-01T00:00:00+00:00'])],
			'expiring-warned' => [$base('c-warned', ['consentExpiresAt' => $soon, 'expiryWarnedAt' => '2025-10-02T00:00:00+00:00', 'lastSyncAt' => '2025-10-01T00:00:00+00:00'])],
			'valid-consent' => [$base('c-valid', ['consentExpiresAt' => $later, 'lastSyncAt' => '2025-10-01T00:00:00+00:00'])],
			'no-accounts' => [$base('c-empty', ['accounts' => [], 'lastSyncAt' => '2025-10-01T00:00:00+00:00'])],
			'pull-fails' => [$base('c-fail', ['accounts' => [['aggregatorAccountId' => 'acc-1'], ['aggregatorAccountId' => 'acc-fail']], 'lastSyncAt' => '2025-10-01T00:00:00+00:00'])],
			'consent-revoked' => [$base('c-gone', ['accounts' => [['aggregatorAccountId' => 'acc-revoked']], 'lastSyncAt' => '2025-10-01T00:00:00+00:00'])],
			'no-source' => [$base('c-nosource', ['aggregatorSourceSlug' => ''])],
			'disabled-source' => [$base('c-disabled', ['aggregatorSourceSlug' => 'psd2-disabled'])],
			'one-fails-others-sync' => [
				$base('c-a', ['lastSyncAt' => '2025-10-01T00:00:00+00:00']),
				$base('c-b', ['accounts' => [['aggregatorAccountId' => 'acc-fail']]]),
				$base('c-c', ['accounts' => [['aggregatorAccountId' => 'acc-3', 'iban' => 'NL33']], 'lastSyncAt' => '2025-10-03T00:00:00+00:00']),
			],
		];

	}//end sweeps()

	/**
	 * Build the service with mocks that record every call into the trace.
	 *
	 * @param array<int, array<string, mixed>> $connections The connections findAll() answers with.
	 *
	 * @return BankfeedSyncService
	 */
	private function service(array $connections): BankfeedSyncService {
		$objects = ObjectServiceMockBuilder::make($this);
		$objects->method('findAll')->willReturnCallback(
			function () use ($connections): array {
				$entities = [];
				foreach ($connections as $i => $data) {
					$entities[] = ObjectServiceMockBuilder::objectEntity($this, $data, 'conn-uuid-' . $i);
				}

				return ['results' => $entities];
			}
		);
		$objects->method('find')->willReturnCallback(
			function (...$args): ObjectEntity {
				$id = (string)($args['id'] ?? $args[0]);
				$sources = [
					'psd2-log' => ['type' => 'psd2', 'configuration' => ['provider' => 'log', 'expiryWarningDays' => 7]],
					'psd2-rest' => ['type' => 'psd2', 'isEnabled' => true, 'configuration' => ['provider' => 'rest']],
					'psd2-disabled' => ['type' => 'psd2', 'isEnabled' => false, 'configuration' => []],
				];
				return ObjectServiceMockBuilder::objectEntity($this, $sources[$id], 'source-' . $id);
			}
		);
		$batches = 0;
		$objects->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null) use (&$batches): ObjectEntity {
				$this->trace[] = ['save' => $schema, 'uuid' => $uuid, 'object' => $this->stable(value: $object)];
				if ($uuid === null) {
					$batches++;
					$uuid = 'batch-' . $batches;
				}

				return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid);
			}
		);

		$events = $this->createMock(EventService::class);
		$events->method('emitCloudEvent')->willReturnCallback(
			function (string $type, string $source, ?string $subject, array $data): array {
				$this->trace[] = ['event' => $type, 'source' => $source, 'subject' => $subject, 'data' => $this->stable(value: $data)];
				return [];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->trace[] = ['log' => $level, 'message' => (string)$message, 'context' => $this->stable(value: $context)];
				}
			);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new BankfeedSyncService(
			$objects,
			$this->provider(class: LogPsd2AggregatorProvider::class, name: 'log'),
			$this->provider(class: RestPsd2AggregatorProvider::class, name: 'rest'),
			$events,
			$l10n,
			$logger,
			$this->createMock(ContainerInterface::class)
		);

	}//end service()

	/**
	 * A provider binding that records each pull.
	 *
	 * @param class-string $class The provider class.
	 * @param string       $name  The binding name in the trace.
	 *
	 * @return LogPsd2AggregatorProvider|RestPsd2AggregatorProvider
	 */
	private function provider(string $class, string $name): object {
		$provider = $this->createMock($class);
		$provider->method('listTransactions')->willReturnCallback(
			function (array $sourceConfiguration, string $accountId, string $since, string $until) use ($name): array {
				$this->trace[] = ['pull' => $name, 'configuration' => $sourceConfiguration, 'accountId' => $accountId, 'since' => $this->stable(value: $since), 'until' => $this->stable(value: $until)];
				if ($accountId === 'acc-fail') {
					throw new Psd2ProviderException(message: 'aggregator timeout');
				}

				if ($accountId === 'acc-revoked') {
					throw new Psd2ConsentRevokedException(message: 'consent withdrawn at the bank');
				}

				return [['id' => $accountId . '-t1', 'amount' => '12.50'], ['id' => $accountId . '-t2', 'amount' => '-3.00']];
			}
		);

		return $provider;

	}//end provider()

	/**
	 * A recorded value with each clock time replaced by its distance from now in days.
	 *
	 * @param mixed $value The recorded value.
	 *
	 * @return mixed
	 */
	private function stable(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->stable(value: $item), $value);
		}

		if (is_string($value) === true && preg_match('/^\d{4}-\d\d-\d\dT/', $value) === 1 && str_starts_with($value, '2025-') === false) {
			$days = (int)round(((new DateTime($value))->getTimestamp() - time()) / 86400);
			return sprintf('clock%+dd', $days);
		}

		return $value;

	}//end stable()

	/**
	 * Every sweep matches the golden file.
	 *
	 * @return void
	 */
	public function testEverySweepMatchesTheGoldenFile(): void {
		$actual = [];
		foreach ($this->sweeps() as $name => $connections) {
			$service = $this->service(connections: $connections);
			$this->trace = [];
			try {
				$outcome = ['return' => $service->syncAll()];
			} catch (\Throwable $exception) {
				$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
			}

			$actual[$name] = ['trace' => $this->trace, 'outcome' => $outcome];
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('BANKFEED_SWEEP_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame((string)file_get_contents(self::GOLDEN), $encoded);

	}//end testEverySweepMatchesTheGoldenFile()
}//end class
