<?php

/**
 * Unit tests for ExchangeRateImportService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\ExchangeRate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-the-ecb-reference-rates-are-stored-every-working-day-req-fx-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\ExchangeRate;

use OCA\Integriq\Service\EventServiceInterface;
use OCA\Integriq\Service\ExchangeRate\EcbReferenceRateParser;
use OCA\Integriq\Service\ExchangeRate\ExchangeRateImportService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Runs the import against an in-memory object store and an HTTP client that
 * answers with the stored ECB files under tests/fixtures/ecb/. No network.
 *
 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-the-ecb-reference-rates-are-stored-every-working-day-req-fx-001
 */
class ExchangeRateImportServiceTest extends TestCase {

	/**
	 * Directory of the stored ECB files.
	 *
	 * @var string
	 */
	private const FIXTURES = __DIR__ . '/../../../fixtures/ecb/';

	/**
	 * Daily file URL of the seeded source.
	 *
	 * @var string
	 */
	private const DAILY = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

	/**
	 * History file URL of the seeded source.
	 *
	 * @var string
	 */
	private const HISTORY = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml';

	/**
	 * Stored exchange_rate rows.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Every save call, with its register, schema and rbac flag.
	 *
	 * @var list<array{register: ?string, schema: ?string, rbac: bool}>
	 */
	private array $saves = [];

	/**
	 * Emitted CloudEvents.
	 *
	 * @var list<array{type: string, subject: ?string, data: array}>
	 */
	private array $events = [];

	/**
	 * URLs the HTTP client was asked for.
	 *
	 * @var list<string>
	 */
	private array $fetched = [];

	/**
	 * The seeded source object, or null when it is missing.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $source = null;

	/**
	 * Seed the source as the register fragment ships it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$fragment = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../../lib/Settings/register.d/ecb-exchange-rates-source.json'),
			true
		);
		$this->source = $fragment['components']['objects'][0];

	}//end setUp()

	/**
	 * Build the service on the in-memory store and the fixture-backed client.
	 *
	 * @param int $status HTTP status the client answers with.
	 *
	 * @return ExchangeRateImportService
	 */
	private function makeService(int $status = 200): ExchangeRateImportService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function ($id) {
				if ($this->source === null || $id !== ExchangeRateImportService::SOURCE_SLUG) {
					throw new DoesNotExistException('not found');
				}

				$entity = new ObjectEntity();
				$entity->setObject($this->source);
				return $entity;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []) {
				$filters = ($config['filters'] ?? []);
				$results = [];
				foreach ($this->rows as $row) {
					if (isset($filters['rateDate']) === true && $row['rateDate'] !== $filters['rateDate']) {
						continue;
					}

					if (isset($filters['source']) === true && $row['source'] !== $filters['source']) {
						continue;
					}

					$entity = new ObjectEntity();
					$entity->setObject($row);
					$results[] = $entity;
				}

				return ['results' => $results];
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function ($object, $register = null, $schema = null, $uuid = null, $_rbac = true) {
				$this->rows[]  = $object;
				$this->saves[] = ['register' => $register, 'schema' => $schema, 'rbac' => $_rbac];
				$entity = new ObjectEntity();
				$entity->setObject($object);
				return $entity;
			}
		);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $uri) use ($status) {
				$this->fetched[] = $uri;
				$file     = ($uri === self::HISTORY) ? 'eurofxref-hist-90d.xml' : 'eurofxref-daily.xml';
				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($status);
				$response->method('getBody')->willReturn((string)file_get_contents(self::FIXTURES . $file));
				return $response;
			}
		);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$eventService = $this->createMock(EventServiceInterface::class);
		$eventService->method('emitCloudEvent')->willReturnCallback(
			function (string $type, string $source, ?string $subject, array $data) {
				$this->events[] = ['type' => $type, 'subject' => $subject, 'data' => $data];
				return [];
			}
		);

		return new ExchangeRateImportService(
			$objectService,
			$clientService,
			new EcbReferenceRateParser(),
			$eventService,
			new NullLogger()
		);

	}//end makeService()

	/**
	 * Store one day of rates directly, as an earlier run would have.
	 *
	 * @return void
	 */
	private function seedDailyRates(): void {
		$this->makeService()->storeRates(
			(new EcbReferenceRateParser())->parse((string)file_get_contents(self::FIXTURES . 'eurofxref-daily.xml'))
		);
		$this->events  = [];
		$this->saves   = [];
		$this->fetched = [];

	}//end seedDailyRates()

	/**
	 * Scenario "the daily run stores Friday's rates": two runs, one rate per
	 * currency for the date and one published event.
	 *
	 * @return void
	 */
	public function testTwoDailyRunsStoreEachCurrencyOnce(): void {
		$this->seedDailyRates();
		$this->rows = array_values(array_filter($this->rows, static fn (array $row): bool => $row['currency'] !== 'USD'));

		$first  = $this->makeService()->importFromSource();
		$second = $this->makeService()->importFromSource();

		$this->assertSame([self::DAILY, self::DAILY], $this->fetched);
		$this->assertSame(['status' => 'stored', 'stored' => 1, 'dates' => []], $first);
		$this->assertSame(['status' => 'stored', 'stored' => 0, 'dates' => []], $second);
		$this->assertCount(29, $this->rows);
		$this->assertSame([], $this->events, 'Completing a date already announced emits nothing.');

	}//end testTwoDailyRunsStoreEachCurrencyOnce()

	/**
	 * A new date is stored as exchange_rate objects in register integriq, in
	 * system context, and announced once.
	 *
	 * @return void
	 */
	public function testNewDateIsStoredAndAnnouncedOnce(): void {
		$this->seedDailyRates();
		$this->rows = [['baseCurrency' => 'EUR', 'currency' => 'USD', 'rateDate' => '2026-10-29', 'rate' => 1.1, 'source' => 'ecb']];

		$result = $this->makeService()->importFromSource();
		$this->makeService()->importFromSource();

		$this->assertSame(['2026-10-30'], $result['dates']);
		$this->assertSame(29, $result['stored']);
		$this->assertCount(30, $this->rows);
		$this->assertCount(1, $this->events);
		$this->assertSame('nl.conduction.fx.rates.published', $this->events[0]['type']);
		$this->assertSame('2026-10-30', $this->events[0]['data']['rateDate']);
		$this->assertSame('ecb', $this->events[0]['data']['source']);
		$this->assertContains('USD', $this->events[0]['data']['currencies']);
		$this->assertCount(29, $this->events[0]['data']['currencies']);

		$usd = array_values(array_filter($this->rows, static fn (array $row): bool => $row['currency'] === 'USD' && $row['rateDate'] === '2026-10-30'));
		$this->assertSame(
			[['baseCurrency' => 'EUR', 'currency' => 'USD', 'rateDate' => '2026-10-30', 'rate' => 1.1172, 'source' => 'ecb']],
			$usd
		);
		foreach ($this->saves as $save) {
			$this->assertSame(['register' => 'integriq', 'schema' => 'exchange_rate', 'rbac' => false], $save);
		}

	}//end testNewDateIsStoredAndAnnouncedOnce()

	/**
	 * The first run against an empty store reads the history file.
	 *
	 * @return void
	 */
	public function testFirstRunFillsFromTheHistoryFile(): void {
		$result = $this->makeService()->importFromSource();

		$this->assertSame([self::HISTORY], $this->fetched);
		$this->assertSame(['2026-10-30', '2026-10-29', '2026-10-28'], $result['dates']);
		$this->assertSame(87, $result['stored']);
		$this->assertCount(3, $this->events);

		$this->makeService()->importFromSource();
		$this->assertSame([self::HISTORY, self::DAILY], $this->fetched, 'Later runs read the daily file.');
		$this->assertCount(87, $this->rows);

	}//end testFirstRunFillsFromTheHistoryFile()

	/**
	 * A disabled or missing source fetches nothing.
	 *
	 * @return void
	 */
	public function testDisabledOrMissingSourceIsSkipped(): void {
		$this->source['isEnabled'] = false;
		$this->assertSame(['status' => 'skipped', 'stored' => 0, 'dates' => []], $this->makeService()->importFromSource());

		$this->source = null;
		$this->assertSame(['status' => 'skipped', 'stored' => 0, 'dates' => []], $this->makeService()->importFromSource());

		$this->assertSame([], $this->fetched);
		$this->assertSame([], $this->rows);

	}//end testDisabledOrMissingSourceIsSkipped()

	/**
	 * A failed fetch throws and keeps the stored rates.
	 *
	 * @return void
	 */
	public function testFailedFetchKeepsStoredRates(): void {
		$this->seedDailyRates();
		$before = $this->rows;

		try {
			$this->makeService(503)->importFromSource();
			$this->fail('A 503 must not pass as an empty file.');
		} catch (RuntimeException $exception) {
			$this->assertStringContainsString('HTTP 503', $exception->getMessage());
		}

		$this->assertSame($before, $this->rows);
		$this->assertSame([], $this->events);

	}//end testFailedFetchKeepsStoredRates()
}//end class
