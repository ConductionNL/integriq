<?php

/**
 * OpenConnector Corporate Card-Feed Sync Service.
 *
 * Core of the corporate-card-feed connector: resolves the configured card
 * program source + provider binding, enrolls a source (discovers its cards), and
 * runs the scheduled transaction sync that persists `cardfeed_batch` objects and
 * emits `nl.conduction.cardfeed.transactions.synced` CloudEvents. The sync is
 * idempotent on transaction id (REQ-004): each `cardfeed_account` records the
 * transaction ids already emitted, and a sweep only persists/emits the
 * transactions it has not seen before — so a replayed sync produces no batch and
 * no event. This connector never mutates a consuming app's records — it only
 * emits events (ADR-022).
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/corporate-card-feed/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use DateInterval;
use DateTime;
use OCA\OpenConnector\Exception\CardfeedProviderException;
use OCA\OpenConnector\Service\Cardfeed\CardfeedProviderInterface;
use OCA\OpenConnector\Service\Cardfeed\LogCardfeedProvider;
use OCA\OpenConnector\Service\Cardfeed\RestCardfeedProvider;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drives corporate-card enrollment, card discovery, and the idempotent scheduled sync.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/specs/corporate-card-feed/spec.md
 */
class CardfeedSyncService {

	/**
	 * OpenRegister register slug holding cardfeed sources, accounts, and batches.
	 *
	 * @var string
	 */
	public const REGISTER = 'openconnector';

	/**
	 * OR schema slug for a cardfeed program source.
	 *
	 * @var string
	 */
	public const SCHEMA_SOURCE = 'source';

	/**
	 * OR schema slug for a cardfeed_account record.
	 *
	 * @var string
	 */
	public const SCHEMA_ACCOUNT = 'cardfeed_account';

	/**
	 * OR schema slug for a cardfeed_batch record.
	 *
	 * @var string
	 */
	public const SCHEMA_BATCH = 'cardfeed_batch';

	/**
	 * `source.type` value identifying a corporate-card program source.
	 *
	 * @var string
	 */
	public const SOURCE_TYPE = 'cardfeed';

	/**
	 * CloudEvent type emitted for every persisted transactions batch.
	 *
	 * @var string
	 */
	public const EVENT_TYPE_TRANSACTIONS_SYNCED = 'nl.conduction.cardfeed.transactions.synced';

	/**
	 * Upper bound on `seenTransactionIds` per account (oldest evicted). Keeps
	 * the idempotency set from growing without limit; eviction is safe because
	 * the watermark advances past evicted ids' windows.
	 *
	 * @var integer
	 */
	public const MAX_SEEN_TRANSACTION_IDS = 10000;

	/**
	 * Default backfill window in days for an account that has never synced.
	 *
	 * @var integer
	 */
	private const DEFAULT_BACKFILL_DAYS = 30;

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService OR object service for source/account/batch persistence.
	 * @param LogCardfeedProvider $logProvider The sandbox provider binding.
	 * @param RestCardfeedProvider $restProvider The generic REST provider binding.
	 * @param EventService $eventService Emits the synced CloudEvent.
	 * @param IL10N $l The localization service (operator-facing detail text).
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly LogCardfeedProvider $logProvider,
		private readonly RestCardfeedProvider $restProvider,
		private readonly EventService $eventService,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Enroll a card program: discover its cards and persist (or update in place)
	 * a `cardfeed_account`.
	 *
	 * Idempotent: re-enrolling the same source updates the card set on the
	 * existing account (keyed by cardId) rather than creating a second account
	 * or duplicating a card (REQ-002).
	 *
	 * @param string $sourceSlug The cardfeed source slug (openconnector Source).
	 *
	 * @return array<string, mixed> The enrolled account summary
	 *                              (`accountId`, `cardfeedSourceSlug`, `cards`, `lifecycleState`).
	 *
	 * @throws CardfeedProviderException When the source is unknown/disabled or the provider errors.
	 *
	 * @spec openspec/specs/corporate-card-feed/spec.md#scenario-enrollment-discovers-and-records-cards-idempotently
	 */
	public function enrollSource(string $sourceSlug): array {
		$source = $this->resolveSource(sourceSlug: $sourceSlug);
		$configuration = ($source->getObject()['configuration'] ?? []);
		$provider = $this->resolveProvider(configuration: $configuration);

		$cards = $this->dedupeCards(cards: $provider->listCards(sourceConfiguration: $configuration));

		$existing = $this->findAccountBySourceSlug(sourceSlug: $sourceSlug);
		if ($existing !== null) {
			$data = (array)$existing->getObject();
			$data['cards'] = $cards;
			$data['provider'] = $this->resolveProviderName(configuration: $configuration);
			$data['lifecycleState'] = 'active';

			$this->objectService->saveObject(
				object: $data,
				register: self::REGISTER,
				schema: self::SCHEMA_ACCOUNT,
				uuid: $existing->getUuid()
			);

			return [
				'accountId' => (string)($data['accountId'] ?? ''),
				'cardfeedSourceSlug' => $sourceSlug,
				'cards' => $cards,
				'lifecycleState' => 'active',
			];
		}//end if

		$accountId = $this->generateUuid();
		$this->objectService->saveObject(
			object: [
				'accountId' => $accountId,
				'cardfeedSourceSlug' => $sourceSlug,
				'provider' => $this->resolveProviderName(configuration: $configuration),
				'cards' => $cards,
				'seenTransactionIds' => [],
				'lifecycleState' => 'active',
			],
			register: self::REGISTER,
			schema: self::SCHEMA_ACCOUNT
		);

		return [
			'accountId' => $accountId,
			'cardfeedSourceSlug' => $sourceSlug,
			'cards' => $cards,
			'lifecycleState' => 'active',
		];

	}//end enrollSource()

	/**
	 * Run one sync sweep over every cardfeed account.
	 *
	 * Per account: skip if not `active` (no provider call), else pull each card's
	 * transactions from the last watermark, drop any transaction id already in
	 * `seenTransactionIds`, persist one `cardfeed_batch` per card that has new
	 * transactions, emit the synced event, record the new ids, and advance the
	 * watermark. A failed pull leaves the watermark and seen set untouched so the
	 * same window is re-attempted (REQ-003). A replayed sweep whose transactions
	 * are all already seen persists nothing and emits nothing (REQ-004).
	 *
	 * @return integer The number of batches persisted in this sweep.
	 *
	 * @spec openspec/specs/corporate-card-feed/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-003
	 */
	public function syncAll(): int {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_ACCOUNT,
				],
			]
		);
		$results = ($matches['results'] ?? $matches);

		$batchCount = 0;
		foreach ($results as $account) {
			try {
				$batchCount += $this->syncAccount(account: $account);
			} catch (Throwable $exception) {
				// Watermark and seen set untouched — the same window re-attempts next sweep.
				$this->logger->warning(
					'[CardfeedSyncService] account sync failed; watermark not advanced',
					[
						'accountId' => ($account->getObject()['accountId'] ?? null),
						'exception' => $exception->getMessage(),
					]
				);
			}
		}//end foreach

		return $batchCount;
	}//end syncAll()

	/**
	 * Sync one account: lifecycle guard, per-card pulls, transaction-id dedup,
	 * batch persistence, event emission.
	 *
	 * All card pulls happen BEFORE any batch is persisted so a mid-pull failure
	 * persists nothing and the watermark stays put — no gaps, no double-counting
	 * on the re-attempt.
	 *
	 * @param ObjectEntity $account The cardfeed_account to sync.
	 *
	 * @return integer The number of batches persisted for this account.
	 *
	 * @throws CardfeedProviderException When the source or a pull fails (watermark not advanced).
	 */
	private function syncAccount(ObjectEntity $account): int {
		$data = (array)$account->getObject();

		if (($data['lifecycleState'] ?? '') !== 'active') {
			// Not syncable (pending / disabled): no provider call.
			return 0;
		}

		$source = $this->resolveSource(sourceSlug: (string)($data['cardfeedSourceSlug'] ?? ''));
		$configuration = ($source->getObject()['configuration'] ?? []);
		$provider = $this->resolveProvider(configuration: $configuration);

		$defaultSince = (new DateTime())->sub(new DateInterval('P' . self::DEFAULT_BACKFILL_DAYS . 'D'))->format('c');
		$since = (string)(($data['lastSyncAt'] ?? null) ?? $defaultSince);
		$until = (new DateTime())->format('c');

		$seen = array_values(array_unique(array_map('strval', (array)($data['seenTransactionIds'] ?? []))));

		// Phase 1 — pull every card first (any throw aborts before anything is
		// persisted) and dedup against the seen set so only new rows remain.
		$pulled = $this->pullNewTransactions(
			configuration: $configuration,
			provider: $provider,
			cards: (array)($data['cards'] ?? []),
			seen: $seen,
			since: $since,
			until: $until
		);

		// Phase 2 — persist a batch per card with new transactions and emit.
		$emitted = $this->persistBatchesAndEmit(pulled: $pulled, data: $data, since: $since, until: $until);
		$batchCount = $emitted['count'];

		// Advance the watermark on every successful sweep; record the new seen
		// ids (trimmed to the bound) only when there were any.
		$data['lastSyncAt'] = $until;
		if (empty($emitted['newIds']) === false) {
			$merged = array_merge($seen, $emitted['newIds']);
			if (count($merged) > self::MAX_SEEN_TRANSACTION_IDS) {
				$merged = array_slice($merged, (self::MAX_SEEN_TRANSACTION_IDS * -1));
			}

			$data['seenTransactionIds'] = array_values($merged);
		}

		$this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA_ACCOUNT,
			uuid: $account->getUuid()
		);

		// Keep the in-memory entity in step for callers holding the reference.
		$account->setObject($data);

		if ($batchCount > 0) {
			$this->logger->info(
				$this->l->t('Transactions synced'),
				['accountId' => ($data['accountId'] ?? null), 'batches' => $batchCount]
			);
		}

		return $batchCount;
	}//end syncAccount()

	/**
	 * Pull each card's transactions and keep only those whose id is not already
	 * seen (idempotency dedup, REQ-004). Deduplicates within the pulled window too.
	 *
	 * @param array $configuration The cardfeed source's `configuration` object.
	 * @param CardfeedProviderInterface $provider The resolved provider binding.
	 * @param array<int, array<string, mixed>> $cards The account's cards.
	 * @param array<int, string> $seen The transaction ids already emitted.
	 * @param string $since ISO 8601 start of the pull window.
	 * @param string $until ISO 8601 end of the pull window.
	 *
	 * @return array<int, array{cardId: string, transactions: array<int, array<string, mixed>>}> New rows per card.
	 *
	 * @throws CardfeedProviderException When a provider pull fails (nothing persisted, watermark untouched).
	 *
	 * @spec openspec/specs/corporate-card-feed/spec.md#requirement-idempotent-sync-on-transaction-id-req-004
	 */
	private function pullNewTransactions(
		array $configuration,
		CardfeedProviderInterface $provider,
		array $cards,
		array $seen,
		string $since,
		string $until,
	): array {
		$seenIndex = array_flip($seen);

		$pulled = [];
		foreach ($cards as $card) {
			$cardId = (string)($card['cardId'] ?? '');
			if ($cardId === '') {
				continue;
			}

			$newRows = [];
			foreach ($provider->listTransactions(
				sourceConfiguration: $configuration,
				cardId: $cardId,
				since: $since,
				until: $until
			) as $row) {
				$txId = (string)($row['transactionId'] ?? '');
				if ($txId === '' || isset($seenIndex[$txId]) === true) {
					continue;
				}

				// Guard against duplicate ids within the same pulled window too.
				$seenIndex[$txId] = true;
				$newRows[] = $row;
			}

			if (empty($newRows) === false) {
				$pulled[] = ['cardId' => $cardId, 'transactions' => $newRows];
			}
		}//end foreach

		return $pulled;
	}//end pullNewTransactions()

	/**
	 * Persist one `cardfeed_batch` per card with new transactions and emit the
	 * synced CloudEvent for each (REQ-003).
	 *
	 * @param array<int, array{cardId: string, transactions: array<int, array<string, mixed>>}> $pulled New rows per card.
	 * @param array<string, mixed> $data The owning account's data (for accountId).
	 * @param string $since ISO 8601 start of the pulled window.
	 * @param string $until ISO 8601 end of the pulled window.
	 *
	 * @return array{count: int, newIds: array<int, string>} The batch count and the newly emitted transaction ids.
	 *
	 * @spec openspec/specs/corporate-card-feed/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-003
	 */
	private function persistBatchesAndEmit(array $pulled, array $data, string $since, string $until): array {
		$batchCount = 0;
		$newIds = [];
		foreach ($pulled as $pull) {
			$batch = $this->objectService->saveObject(
				object: [
					'accountId' => (string)($data['accountId'] ?? ''),
					'cardId' => $pull['cardId'],
					'since' => $since,
					'until' => $until,
					'transactionCount' => count($pull['transactions']),
					'transactions' => $pull['transactions'],
				],
				register: self::REGISTER,
				schema: self::SCHEMA_BATCH
			);

			$this->eventService->emitCloudEvent(
				type: self::EVENT_TYPE_TRANSACTIONS_SYNCED,
				source: '/cardfeed/batches/' . $batch->getUuid(),
				subject: (string)($data['accountId'] ?? ''),
				data: [
					'accountId' => ($data['accountId'] ?? null),
					'cardId' => $pull['cardId'],
					'since' => $since,
					'until' => $until,
					'transactionCount' => count($pull['transactions']),
					'batchUri' => '/apps/openregister/api/objects/openconnector/cardfeed_batch/' . $batch->getUuid(),
				]
			);

			foreach ($pull['transactions'] as $row) {
				$newIds[] = (string)($row['transactionId'] ?? '');
			}

			$batchCount++;
		}//end foreach

		return ['count' => $batchCount, 'newIds' => $newIds];
	}//end persistBatchesAndEmit()

	/**
	 * Resolve the enabled cardfeed source for a slug.
	 *
	 * @param string $sourceSlug The openconnector Source slug (or uuid).
	 *
	 * @return ObjectEntity The resolved source.
	 *
	 * @throws CardfeedProviderException When the source is missing, not `type=cardfeed`, or disabled.
	 *
	 * @spec openspec/specs/corporate-card-feed/spec.md#requirement-card-provider-abstraction-with-log-and-generic-rest-bindings-req-001
	 */
	public function resolveSource(string $sourceSlug): ObjectEntity {
		if ($sourceSlug === '') {
			throw new CardfeedProviderException(message: 'A cardfeed source slug is required.');
		}

		try {
			$source = $this->objectService->find(id: $sourceSlug);
		} catch (Throwable $exception) {
			throw new CardfeedProviderException(
				message: 'Cardfeed source "' . $sourceSlug . '" could not be resolved (register "openconnector", schema '
					. '"source"). Configure the card program source before using the card feed connector.',
				previous: $exception
			);
		}

		$data = $source->getObject();
		if (($data['type'] ?? '') !== self::SOURCE_TYPE) {
			throw new CardfeedProviderException(
				message: 'Source "' . $sourceSlug . '" is not a cardfeed source (expected type "cardfeed").'
			);
		}

		if (($data['isEnabled'] ?? true) === false) {
			throw new CardfeedProviderException(
				message: 'Cardfeed source "' . $sourceSlug . '" is disabled. Enable it before enrolling cards.'
			);
		}

		return $source;
	}//end resolveSource()

	/**
	 * Select the provider binding named by `configuration.provider` (default `log`).
	 *
	 * @param array $configuration The cardfeed source's `configuration` object.
	 *
	 * @return CardfeedProviderInterface The resolved provider binding.
	 *
	 * @spec openspec/specs/corporate-card-feed/spec.md#requirement-card-provider-abstraction-with-log-and-generic-rest-bindings-req-001
	 */
	public function resolveProvider(array $configuration): CardfeedProviderInterface {
		if (($configuration['provider'] ?? 'log') === 'rest') {
			return $this->restProvider;
		}

		return $this->logProvider;
	}//end resolveProvider()

	/**
	 * Deduplicate a discovered card set in place, keyed by cardId (latest wins).
	 *
	 * @param array<int, array<string, mixed>> $cards The provider-reported cards.
	 *
	 * @return array<int, array<string, string>> The deduplicated card set.
	 */
	private function dedupeCards(array $cards): array {
		$deduped = [];
		foreach ($cards as $card) {
			$key = (string)($card['cardId'] ?? '');
			if ($key === '') {
				continue;
			}

			$deduped[$key] = [
				'cardId' => $key,
				'last4' => (string)($card['last4'] ?? ''),
				'cardholderName' => (string)($card['cardholderName'] ?? ''),
				'currency' => (string)($card['currency'] ?? ''),
			];
		}

		return array_values($deduped);
	}//end dedupeCards()

	/**
	 * Resolve the card-provider vendor label for a source configuration.
	 *
	 * @param array $configuration The cardfeed source's `configuration` object.
	 *
	 * @return string The vendor label (`sandbox` for the log provider unless overridden).
	 */
	private function resolveProviderName(array $configuration): string {
		$explicit = (string)($configuration['cardProvider'] ?? '');
		if ($explicit !== '') {
			return $explicit;
		}

		if (($configuration['provider'] ?? 'log') === 'rest') {
			return 'stripe-issuing';
		}

		return 'sandbox';
	}//end resolveProviderName()

	/**
	 * Find the cardfeed_account for a source slug.
	 *
	 * @param string $sourceSlug The cardfeed source slug.
	 *
	 * @return ObjectEntity|null The account, or null when none matches.
	 */
	private function findAccountBySourceSlug(string $sourceSlug): ?ObjectEntity {
		if ($sourceSlug === '') {
			return null;
		}

		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_ACCOUNT,
					'cardfeedSourceSlug' => $sourceSlug,
				],
				'limit' => 1,
			]
		);
		$results = ($matches['results'] ?? $matches);

		if (empty($results) === true) {
			return null;
		}

		return $results[0];
	}//end findAccountBySourceSlug()

	/**
	 * Generate a RFC 4122 v4 UUID for a new account id.
	 *
	 * @return string The generated UUID.
	 */
	private function generateUuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}//end generateUuid()
}//end class
