<?php

/**
 * Integriq ExchangeRateImportService.
 *
 * Fetches the ECB euro reference rates from the seeded `ecb-eurofxref-daily`
 * source and stores them as `exchange_rate` objects in register `integriq`.
 *
 * @category Service
 * @package  OCA\Integriq\Service\ExchangeRate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-the-ecb-reference-rates-are-stored-every-working-day-req-fx-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\ExchangeRate;

use OCA\Integriq\Service\EventServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Stores one `exchange_rate` per currency per publication date, never twice.
 *
 * The first run against an empty store reads the 90-day history file named
 * in the source's `configuration.historyLocation`; every later run reads the
 * daily file at the source's `location`. A failed fetch or parse stores
 * nothing and leaves the stored rates as they are.
 *
 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-the-ecb-reference-rates-are-stored-every-working-day-req-fx-001
 */
class ExchangeRateImportService {

	/**
	 * Register holding the rates.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * Schema of one rate.
	 *
	 * @var string
	 */
	public const SCHEMA = 'exchange_rate';

	/**
	 * Slug of the seeded ECB source.
	 *
	 * @var string
	 */
	public const SOURCE_SLUG = 'ecb-eurofxref-daily';

	/**
	 * Value of `source` on every rate this service stores.
	 *
	 * @var string
	 */
	public const RATE_SOURCE = 'ecb';

	/**
	 * Base currency of every ECB reference rate.
	 *
	 * @var string
	 */
	public const BASE_CURRENCY = 'EUR';

	/**
	 * CloudEvent type emitted once per newly stored publication date.
	 *
	 * @var string
	 */
	public const EVENT_TYPE_PUBLISHED = 'nl.conduction.fx.rates.published';

	/**
	 * HTTP timeout in seconds for one file download.
	 *
	 * @var integer
	 */
	private const FETCH_TIMEOUT = 30;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object store.
	 * @param IClientService $clientService Nextcloud HTTP client factory.
	 * @param EcbReferenceRateParser $parser Reads the ECB XML files.
	 * @param EventServiceInterface $eventService Emits the published CloudEvent.
	 * @param LoggerInterface $logger Logger for run outcomes.
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-the-ecb-reference-rates-are-stored-every-working-day-req-fx-001
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IClientService $clientService,
		private readonly EcbReferenceRateParser $parser,
		private readonly EventServiceInterface $eventService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Fetch the rates from the seeded source and store the ones not stored yet.
	 *
	 * @return array{status: string, stored: int, dates: list<string>} The outcome:
	 *               status `stored` or `skipped`, the number of rates stored and the newly stored dates.
	 *
	 * @throws RuntimeException When the file cannot be fetched or read.
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-the-ecb-reference-rates-are-stored-every-working-day-req-fx-001
	 */
	public function importFromSource(): array {
		$source = $this->resolveSource();
		if ($source === null) {
			return ['status' => 'skipped', 'stored' => 0, 'dates' => []];
		}

		$location = (string)($source['location'] ?? '');
		$history  = (string)($source['configuration']['historyLocation'] ?? '');
		if ($history !== '' && $this->hasStoredRates() === false) {
			$location = $history;
		}

		if ($location === '') {
			$this->logger->warning('[ExchangeRateImport] source ' . self::SOURCE_SLUG . ' has no location; nothing fetched.');
			return ['status' => 'skipped', 'stored' => 0, 'dates' => []];
		}

		$rates  = $this->parser->parse(xml: $this->fetch(location: $location));
		$result = $this->storeRates(rates: $rates);

		return ['status' => 'stored', 'stored' => $result['stored'], 'dates' => $result['dates']];

	}//end importFromSource()

	/**
	 * Store parsed rates, skipping every currency already stored for its date.
	 *
	 * Emits one `nl.conduction.fx.rates.published` CloudEvent for each date
	 * that had no rate stored before this call.
	 *
	 * @param array<string, array<string, float>> $rates Rates keyed by date, then by currency code.
	 *
	 * @return array{stored: int, dates: list<string>} The number of rates stored and the newly stored dates.
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-the-ecb-reference-rates-are-stored-every-working-day-req-fx-001
	 */
	public function storeRates(array $rates): array {
		$stored   = 0;
		$newDates = [];

		foreach ($rates as $date => $currencies) {
			$date     = (string)$date;
			$existing = $this->storedCurrencies(date: $date);
			$added    = [];

			foreach ($currencies as $currency => $rate) {
				$currency = (string)$currency;
				if (isset($existing[$currency]) === true) {
					continue;
				}

				$this->objectService->saveObject(
					object: [
						'baseCurrency' => self::BASE_CURRENCY,
						'currency'     => $currency,
						'rateDate'     => $date,
						'rate'         => $rate,
						'source'       => self::RATE_SOURCE,
					],
					register: self::REGISTER,
					schema: self::SCHEMA,
					_rbac: false,
					_multitenancy: false
				);
				$added[] = $currency;
				$stored++;
			}

			if ($existing === [] && $added !== []) {
				$newDates[] = $date;
				$this->announce(date: $date, currencies: $added);
			}
		}//end foreach

		return ['stored' => $stored, 'dates' => $newDates];

	}//end storeRates()

	/**
	 * Read the seeded source, or null when it is missing or disabled.
	 *
	 * @return array<string, mixed>|null The source object data.
	 */
	private function resolveSource(): ?array {
		try {
			$entity = $this->objectService->find(
				id: self::SOURCE_SLUG,
				register: self::REGISTER,
				schema: 'source',
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->info('[ExchangeRateImport] source ' . self::SOURCE_SLUG . ' not found: ' . $exception->getMessage());
			return null;
		}

		if ($entity === null) {
			return null;
		}

		$data = $entity->getObject();
		if (($data['isEnabled'] ?? true) === false) {
			return null;
		}

		return $data;

	}//end resolveSource()

	/**
	 * Whether any ECB rate is stored yet.
	 *
	 * @return bool
	 */
	private function hasStoredRates(): bool {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema'   => self::SCHEMA,
					'source'   => self::RATE_SOURCE,
				],
				'limit'   => 1,
			],
			_rbac: false,
			_multitenancy: false
		);

		return (($matches['results'] ?? $matches) !== []);

	}//end hasStoredRates()

	/**
	 * The currencies already stored for one date, as a lookup set.
	 *
	 * @param string $date The publication date (YYYY-MM-DD).
	 *
	 * @return array<string, true>
	 */
	private function storedCurrencies(string $date): array {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema'   => self::SCHEMA,
					'source'   => self::RATE_SOURCE,
					'rateDate' => $date,
				],
				'limit'   => 1000,
			],
			_rbac: false,
			_multitenancy: false
		);

		$currencies = [];
		foreach (($matches['results'] ?? $matches) as $match) {
			$data = (array)$match;
			if ($match instanceof ObjectEntity) {
				$data = $match->getObject();
			}

			if (($data['rateDate'] ?? '') === $date && isset($data['currency']) === true) {
				$currencies[(string)$data['currency']] = true;
			}
		}

		return $currencies;

	}//end storedCurrencies()

	/**
	 * Download one rate file.
	 *
	 * @param string $location The file URL.
	 *
	 * @return string The response body.
	 *
	 * @throws RuntimeException When the download fails or answers non-200.
	 */
	private function fetch(string $location): string {
		try {
			$response = $this->clientService->newClient()->get(
				uri: $location,
				options: ['timeout' => self::FETCH_TIMEOUT, 'headers' => ['Accept' => 'application/xml']]
			);
		} catch (Throwable $exception) {
			throw new RuntimeException('Fetching ' . $location . ' failed: ' . $exception->getMessage(), 0, $exception);
		}

		if ($response->getStatusCode() !== 200) {
			throw new RuntimeException('Fetching ' . $location . ' answered HTTP ' . $response->getStatusCode() . '.');
		}

		return (string)$response->getBody();

	}//end fetch()

	/**
	 * Emit the published CloudEvent for one newly stored date.
	 *
	 * A failed emit is logged and never undoes the stored rates.
	 *
	 * @param string $date The publication date.
	 * @param list<string> $currencies The currencies stored for it.
	 *
	 * @return void
	 */
	private function announce(string $date, array $currencies): void {
		try {
			$this->eventService->emitCloudEvent(
				type: self::EVENT_TYPE_PUBLISHED,
				source: '/exchange-rates/' . self::RATE_SOURCE,
				subject: $date,
				data: [
					'source'     => self::RATE_SOURCE,
					'rateDate'   => $date,
					'currencies' => $currencies,
				]
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ExchangeRateImport] published event for ' . $date . ' not emitted: ' . $exception->getMessage(),
				['exception' => $exception]
			);
		}

	}//end announce()
}//end class
