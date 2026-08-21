<?php

/**
 * OpenConnector Bankfeed Sync Service.
 *
 * Core of the psd2-ais-bank-feed-connector: resolves the configured PSD2
 * aggregator source + provider binding, drives the redirect-based SCA
 * connect/callback flow, discovers authorised accounts, and runs the
 * scheduled transaction sync that persists `bankfeed_batch` objects and emits
 * `nl.conduction.bankfeed.transactions.synced` CloudEvents. Consent lifecycle
 * transitions (granted / expiring / revoked) are emitted from here so a
 * consuming app (shillinq BankConnection) can transition its own state —
 * this connector never mutates a consuming app's records (REQ-005).
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
 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use DateInterval;
use DateTime;
use OCA\OpenConnector\Exception\Psd2ConsentRevokedException;
use OCA\OpenConnector\Exception\Psd2ProviderException;
use OCA\OpenConnector\Service\Psd2\LogPsd2AggregatorProvider;
use OCA\OpenConnector\Service\Psd2\Psd2AggregatorProviderInterface;
use OCA\OpenConnector\Service\Psd2\RestPsd2AggregatorProvider;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drives the PSD2 SCA consent flow, account discovery, and the scheduled bankfeed sync.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.StaticAccess)             -- \OCP\Server::get is the only way to
 *  lazily resolve the OpenRegister ObjectService without a hard dependency.
 *
 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md
 */
class BankfeedSyncService {

	/**
	 * OpenRegister register slug holding PSD2 sources, connections, and batches.
	 *
	 * @var string
	 */
	public const REGISTER = 'openconnector';

	/**
	 * OR schema slug for a PSD2 aggregator source.
	 *
	 * @var string
	 */
	public const SCHEMA_SOURCE = 'source';

	/**
	 * OR schema slug for a bankfeed_connection record.
	 *
	 * @var string
	 */
	public const SCHEMA_CONNECTION = 'bankfeed_connection';

	/**
	 * OR schema slug for a bankfeed_batch record.
	 *
	 * @var string
	 */
	public const SCHEMA_BATCH = 'bankfeed_batch';

	/**
	 * `source.type` value identifying a PSD2 AIS aggregator source.
	 *
	 * @var string
	 */
	public const SOURCE_TYPE = 'psd2';

	/**
	 * CloudEvent type emitted for every persisted transactions batch.
	 *
	 * @var string
	 */
	public const EVENT_TYPE_TRANSACTIONS_SYNCED = 'nl.conduction.bankfeed.transactions.synced';

	/**
	 * CloudEvent type emitted when a consent is granted (successful callback).
	 *
	 * @var string
	 */
	public const EVENT_TYPE_CONSENT_GRANTED = 'nl.conduction.bankfeed.consent.granted';

	/**
	 * CloudEvent type emitted once when a consent enters the expiry warning window.
	 *
	 * @var string
	 */
	public const EVENT_TYPE_CONSENT_EXPIRING = 'nl.conduction.bankfeed.consent.expiring';

	/**
	 * CloudEvent type emitted when a consent is revoked.
	 *
	 * @var string
	 */
	public const EVENT_TYPE_CONSENT_REVOKED = 'nl.conduction.bankfeed.consent.revoked';

	/**
	 * Default expiry warning window in days (overridable per source via
	 * `configuration.expiryWarningDays`).
	 *
	 * @var integer
	 */
	public const DEFAULT_EXPIRY_WARNING_DAYS = 14;

	/**
	 * Default backfill window in days for a connection that has never synced.
	 *
	 * @var integer
	 */
	private const DEFAULT_BACKFILL_DAYS = 30;

	/**
	 * FQCN of the OpenRegister credential-store resolver (resolved lazily —
	 * the class only exists on OpenRegister versions that ship the credential
	 * broker; mirrors BrokeredCallService::BROKER_CLASS).
	 *
	 * @var string
	 */
	public const CREDENTIAL_STORE_RESOLVER_CLASS = 'OCA\OpenRegister\Service\Credential\CredentialStoreResolver';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService OR object service for source/connection/batch persistence.
	 * @param LogPsd2AggregatorProvider $logProvider The sandbox provider binding.
	 * @param RestPsd2AggregatorProvider $restProvider The generic REST provider binding.
	 * @param EventService $eventService Emits the synced + consent-lifecycle CloudEvents.
	 * @param IL10N $l The localization service (operator-facing detail text).
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 * @param callable|null $storeResolver Returns the OpenRegister credential
	 *                                     store to broker consent tokens into.
	 *                                     Injectable so the fail-closed path can
	 *                                     be tested on purpose. It used to be
	 *                                     reached only through \OCP\Server::get(),
	 *                                     which meant the one test covering it
	 *                                     passed because the unit environment had
	 *                                     no container — an accident, not a check.
	 *                                     When the suite began running against a
	 *                                     real Nextcloud the brokering succeeded,
	 *                                     the token was stored correctly, and the
	 *                                     test failed. Null keeps the production
	 *                                     lookup exactly as it was.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly LogPsd2AggregatorProvider $logProvider,
		private readonly RestPsd2AggregatorProvider $restProvider,
		private readonly EventService $eventService,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
		private $storeResolver = null,
	) {

	}//end __construct()

	/**
	 * Start the redirect-based SCA flow: create a requisition and persist a
	 * `pending` bankfeed_connection carrying the redirectUrl registered at
	 * connect time (open-redirect defence — callback only redirects there).
	 *
	 * @param string $sourceSlug The PSD2 source slug (openconnector Source).
	 * @param string $institutionId The aggregator institution (bank) identifier.
	 * @param string $redirectUrl Where the operator's browser returns after bank SCA.
	 *
	 * @return array{redirectUrl: string, reference: string, connectionId: string} The bank SCA URL + identifiers.
	 *
	 * @throws Psd2ProviderException When the source is unknown/disabled or the aggregator errors.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-connect-returns-a-bank-sca-redirect-url
	 */
	public function connect(string $sourceSlug, string $institutionId, string $redirectUrl): array {
		$source = $this->resolveSource(sourceSlug: $sourceSlug);
		$configuration = ($source->getObject()['configuration'] ?? []);
		$provider = $this->resolveProvider(configuration: $configuration);

		$requisition = $provider->createRequisition(
			sourceConfiguration: $configuration,
			institutionId: $institutionId,
			redirectUrl: $redirectUrl
		);

		$connectionId = $this->generateUuid();
		$this->objectService->saveObject(
			object: [
				'connectionId' => $connectionId,
				'aggregatorSourceSlug' => $sourceSlug,
				'aggregator' => $this->resolveAggregatorName(configuration: $configuration),
				'consentReference' => $requisition['reference'],
				'redirectUrl' => $redirectUrl,
				'accounts' => [],
				'lifecycleState' => 'pending',
			],
			register: self::REGISTER,
			schema: self::SCHEMA_CONNECTION
		);

		return [
			'redirectUrl' => $requisition['redirectUrl'],
			'reference' => $requisition['reference'],
			'connectionId' => $connectionId,
		];

	}//end connect()

	/**
	 * Finalise a consent after the bank redirected the operator back.
	 *
	 * Validates the `ref` against the pending requisition created at connect
	 * time (CSRF/mix-up defence): an unknown or already-finalised reference is
	 * rejected. Any token material returned by the provider is stored through
	 * the credential broker — never on the connection object (REQ-002/006).
	 * Emits `nl.conduction.bankfeed.consent.granted` once active.
	 *
	 * @param string $reference The consent reference returned by the aggregator redirect.
	 *
	 * @return array{redirectUrl: string, connectionId: string} The redirect target registered at connect time.
	 *
	 * @throws Psd2ProviderException When the reference is unknown, already finalised, or the aggregator errors.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-callback-finalises-consent-and-stores-only-the-reference
	 */
	public function finaliseConsent(string $reference): array {
		$connection = $this->findConnectionByReference(reference: $reference);
		if ($connection === null) {
			throw new Psd2ProviderException(
				message: 'Unknown consent reference — no pending bankfeed connection matches this callback (rejected).'
			);
		}

		/*
		 * @var array<string, mixed> $data
		 */

		$data = $connection->getObject();

		// The connection AS LOADED, kept unmutated. $data gains consentGrantedAt /
		// consentExpiresAt / accounts / lifecycleState below, and static analysis
		// then narrows it to the keys this method assigns — so a later `??` read of
		// a key it never assigns is reported as an offset that does not exist.
		// Reading identifiers from the loaded copy is both analysable and closer to
		// what they mean. Mirrors $jobConfig in JobService::executeJob().
		$loaded = $data;

		if (($data['lifecycleState'] ?? '') !== 'pending') {
			throw new Psd2ProviderException(
				message: 'This consent reference was already finalised — replayed callbacks are rejected.'
			);
		}

		$source = $this->resolveSource(sourceSlug: (string)($data['aggregatorSourceSlug'] ?? ''));
		$configuration = ($source->getObject()['configuration'] ?? []);
		$provider = $this->resolveProvider(configuration: $configuration);

		$consent = $provider->finaliseConsent(sourceConfiguration: $configuration, reference: $reference);

		$token = ($consent['consentToken'] ?? null);
		if (is_string($token) === true && $token !== '') {
			// Broker the token BEFORE persisting anything so a brokering
			// failure can never leave a plaintext fallback behind (REQ-006).
			$this->brokerConsentToken(token: $token, connectionId: (string)$data['connectionId']);
		}

		$data['consentGrantedAt'] = (new DateTime())->format('c');
		$data['consentExpiresAt'] = (string)($consent['consentExpiresAt'] ?? '');
		$data['accounts'] = array_values((array)($consent['accounts'] ?? []));
		$data['lifecycleState'] = 'active';

		$saved = $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA_CONNECTION,
			uuid: $connection->getUuid()
		);

		$this->emitConsentEvent(type: self::EVENT_TYPE_CONSENT_GRANTED, connection: $saved);

		return [
			'redirectUrl' => (string)($loaded['redirectUrl'] ?? ''),
			'connectionId' => (string)($loaded['connectionId'] ?? ''),
		];

	}//end finaliseConsent()

	/**
	 * Discover (or re-discover) the accounts authorised by an active consent.
	 *
	 * Idempotent: the account set is replaced in place, keyed by aggregator
	 * account id (falling back to IBAN), so re-discovery updates and never
	 * duplicates (REQ-003). No token is read or exposed here.
	 *
	 * @param string $connectionId The connection identifier.
	 *
	 * @return array<int, array<string, string>> The recorded account set.
	 *
	 * @throws Psd2ProviderException When the connection is unknown or not active, or the aggregator errors.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-re-discovery-is-idempotent
	 */
	public function discoverAccounts(string $connectionId): array {
		$connection = $this->findConnectionById(connectionId: $connectionId);
		if ($connection === null) {
			throw new Psd2ProviderException(message: 'Unknown bankfeed connection: ' . $connectionId);
		}

		$data = $connection->getObject();
		if (($data['lifecycleState'] ?? '') !== 'active') {
			throw new Psd2ProviderException(
				message: 'Account discovery requires an active connection; this one is '
					. ((string)($data['lifecycleState'] ?? 'unknown')) . '.'
			);
		}

		$source = $this->resolveSource(sourceSlug: (string)($data['aggregatorSourceSlug'] ?? ''));
		$configuration = ($source->getObject()['configuration'] ?? []);
		$provider = $this->resolveProvider(configuration: $configuration);

		$discovered = $provider->listAccounts(
			sourceConfiguration: $configuration,
			consentReference: (string)($data['consentReference'] ?? '')
		);

		// Replace-in-place keyed by aggregatorAccountId (fallback IBAN): the
		// latest aggregator answer wins, and a re-run can never duplicate.
		$deduped = [];
		foreach ($discovered as $account) {
			$key = (string)$account['aggregatorAccountId'];
			if ($key === '') {
				$key = (string)$account['iban'];
			}

			$deduped[$key] = $account;
		}

		$data['accounts'] = array_values($deduped);

		$this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA_CONNECTION,
			uuid: $connection->getUuid()
		);

		return $data['accounts'];
	}//end discoverAccounts()

	/**
	 * Run one sync sweep over every bankfeed connection.
	 *
	 * Per connection: apply the expiry lifecycle (expired connections are
	 * marked and skipped without an aggregator call; a consent entering the
	 * warning window emits a single expiring event), then pull each account's
	 * transactions from the last watermark, persist one `bankfeed_batch` per
	 * account, emit the synced event, and only then advance the watermark —
	 * a failed pull leaves the watermark untouched so the same window is
	 * re-attempted (REQ-004). A revoked consent moves the connection to
	 * `revoked` and emits the revoked event (REQ-005).
	 *
	 * @return integer The number of batches persisted in this sweep.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-004
	 */
	public function syncAll(): int {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_CONNECTION,
				],
			]
		);
		$results = ($matches['results'] ?? $matches);

		$batchCount = 0;
		foreach ($results as $connection) {
			try {
				$batchCount += $this->syncConnection(connection: $connection);
			} catch (Psd2ConsentRevokedException $exception) {
				$this->markRevoked(connection: $connection, reason: $exception->getMessage());
			} catch (Throwable $exception) {
				// Watermark untouched — the same window re-attempts next sweep.
				$this->logger->warning(
					'[BankfeedSyncService] connection sync failed; watermark not advanced',
					[
						'connectionId' => ($connection->getObject()['connectionId'] ?? null),
						'exception' => $exception->getMessage(),
					]
				);
			}//end try
		}//end foreach

		return $batchCount;
	}//end syncAll()

	/**
	 * Sync one connection: lifecycle guard, per-account pulls, batch persistence, event emission.
	 *
	 * All account pulls happen BEFORE any batch is persisted so a mid-pull
	 * failure persists nothing and the watermark stays put — no gaps, no
	 * double-counting on the re-attempt.
	 *
	 * @param ObjectEntity $connection The bankfeed_connection to sync.
	 *
	 * @return integer The number of batches persisted for this connection.
	 *
	 * @throws Psd2ProviderException When the source or a pull fails (watermark not advanced).
	 * @throws Psd2ConsentRevokedException When the aggregator reports the consent revoked.
	 */
	private function syncConnection(ObjectEntity $connection): int {
		$data = $connection->getObject();

		if ($this->applyExpiryLifecycle(connection: $connection) === false) {
			// Not syncable (pending / expired / revoked): no aggregator call.
			return 0;
		}

		/*
		 * Re-read after the lifecycle pass (it may have saved warning state).
		 *
		 * @var array<string, mixed> $data
		 */

		$data = $connection->getObject();

		// AS LOADED: $data gains lastSyncAt below and analysis then narrows it.
		$loaded = $data;
		$source = $this->resolveSource(sourceSlug: (string)($data['aggregatorSourceSlug'] ?? ''));
		$configuration = ($source->getObject()['configuration'] ?? []);
		$provider = $this->resolveProvider(configuration: $configuration);

		$defaultSince = (new DateTime())->sub(new DateInterval('P' . self::DEFAULT_BACKFILL_DAYS . 'D'))->format('c');
		$since = (string)(($data['lastSyncAt'] ?? null) ?? ($data['consentGrantedAt'] ?? null) ?? $defaultSince);
		$until = (new DateTime())->format('c');

		// Phase 1 — pull every account first; any throw aborts the whole
		// connection before anything is persisted.
		$pulled = [];
		foreach ((array)($data['accounts'] ?? []) as $account) {
			$accountId = (string)($account['aggregatorAccountId'] ?? '');
			if ($accountId === '') {
				continue;
			}

			$pulled[] = [
				'iban' => (string)($account['iban'] ?? ''),
				'transactions' => $provider->listTransactions(
					sourceConfiguration: $configuration,
					accountId: $accountId,
					since: $since,
					until: $until
				),
			];
		}

		// Phase 2 — persist batches + emit events, then advance the watermark.
		$batchCount = 0;
		foreach ($pulled as $pull) {
			$batch = $this->objectService->saveObject(
				object: [
					'connectionId' => (string)($data['connectionId'] ?? ''),
					'accountIban' => $pull['iban'],
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
				source: '/bankfeed/batches/' . $batch->getUuid(),
				subject: (string)($data['connectionId'] ?? ''),
				data: [
					'connectionId' => ($data['connectionId'] ?? null),
					'accountIban' => $pull['iban'],
					'since' => $since,
					'until' => $until,
					'transactionCount' => count($pull['transactions']),
					'batchUri' => '/apps/openregister/api/objects/openconnector/bankfeed_batch/' . $batch->getUuid(),
				]
			);

			$batchCount++;
		}//end foreach

		$data['lastSyncAt'] = $until;
		$this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA_CONNECTION,
			uuid: $connection->getUuid()
		);

		// Keep the in-memory entity in step for callers holding the reference.
		$connection->setObject($data);

		if ($batchCount > 0) {
			$this->logger->info(
				$this->l->t('Transactions synced'),
				['connectionId' => ($loaded['connectionId'] ?? null), 'batches' => $batchCount]
			);
		}

		return $batchCount;
	}//end syncConnection()

	/**
	 * Apply the consent expiry lifecycle to one connection.
	 *
	 * - `consentExpiresAt` in the past → `lifecycleState=expired`, saved; not syncable.
	 * - inside the warning window and not yet warned → emit a single
	 *   `consent.expiring` event and stamp `expiryWarnedAt`; still syncable
	 *   (the consent remains valid until expiry).
	 * - any non-`active` state → not syncable.
	 *
	 * @param ObjectEntity $connection The connection to evaluate.
	 *
	 * @return boolean True when the connection remains syncable (active, unexpired).
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-an-approaching-expiry-emits-an-expiring-event-once
	 */
	private function applyExpiryLifecycle(ObjectEntity $connection): bool {
		/*
		 * @var array<string, mixed> $data
		 */

		$data = $connection->getObject();

		// The connection AS LOADED — $data gains lifecycleState / expiryWarnedAt
		// below, after which static analysis narrows it and the connectionId reads
		// in the log calls report as non-existent offsets. connectionId is never
		// mutated here, so the two are the same value at runtime.
		$loaded = $data;

		if (($data['lifecycleState'] ?? '') !== 'active') {
			return false;
		}

		$expiresAtRaw = (string)($data['consentExpiresAt'] ?? '');
		if ($expiresAtRaw === '') {
			return true;
		}

		$expiresAt = new DateTime($expiresAtRaw);
		$now = new DateTime();

		if ($now >= $expiresAt) {
			$data['lifecycleState'] = 'expired';
			$saved = $this->objectService->saveObject(
				object: $data,
				register: self::REGISTER,
				schema: self::SCHEMA_CONNECTION,
				uuid: $connection->getUuid()
			);
			$connection->setObject($saved->getObject());
			$this->logger->info(
				$this->l->t('Consent expired'),
				['connectionId' => ($data['connectionId'] ?? null)]
			);
			return false;
		}

		$warningDays = $this->resolveWarningDays(sourceSlug: (string)($data['aggregatorSourceSlug'] ?? ''));
		$warnFrom = (clone $expiresAt)->sub(new DateInterval('P' . $warningDays . 'D'));

		if ($now >= $warnFrom && empty($data['expiryWarnedAt']) === true) {
			$data['expiryWarnedAt'] = $now->format('c');
			$saved = $this->objectService->saveObject(
				object: $data,
				register: self::REGISTER,
				schema: self::SCHEMA_CONNECTION,
				uuid: $connection->getUuid()
			);
			$connection->setObject($saved->getObject());
			$this->logger->info(
				$this->l->t('Consent expiring soon'),
				['connectionId' => ($loaded['connectionId'] ?? null), 'consentExpiresAt' => $expiresAtRaw]
			);
			$this->emitConsentEvent(type: self::EVENT_TYPE_CONSENT_EXPIRING, connection: $saved);
		}

		return true;
	}//end applyExpiryLifecycle()

	/**
	 * Move a connection to `revoked` and emit the revoked lifecycle event.
	 *
	 * @param ObjectEntity $connection The connection whose consent was revoked.
	 * @param string $reason Secret-free reason for the log line.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-consent-lifecycle-cloudevents-for-consumer-state-transitions-req-005
	 */
	public function markRevoked(ObjectEntity $connection, string $reason): void {
		/*
		 * @var array<string, mixed> $data
		 */

		$data = $connection->getObject();
		$data['lifecycleState'] = 'revoked';

		$saved = $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA_CONNECTION,
			uuid: $connection->getUuid()
		);

		$this->logger->warning(
			'[BankfeedSyncService] consent revoked',
			['connectionId' => ($data['connectionId'] ?? null), 'reason' => $reason]
		);

		$this->emitConsentEvent(type: self::EVENT_TYPE_CONSENT_REVOKED, connection: $saved);

	}//end markRevoked()

	/**
	 * Resolve the enabled PSD2 source for a slug.
	 *
	 * @param string $sourceSlug The openconnector Source slug (or uuid).
	 *
	 * @return ObjectEntity The resolved source.
	 *
	 * @throws Psd2ProviderException When the source is missing, not `type=psd2`, or disabled.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-aggregator-provider-abstraction-with-log-and-generic-rest-bindings-req-001
	 */
	public function resolveSource(string $sourceSlug): ObjectEntity {
		if ($sourceSlug === '') {
			throw new Psd2ProviderException(message: 'A PSD2 source slug is required.');
		}

		try {
			$source = $this->objectService->find(id: $sourceSlug);
		} catch (Throwable $exception) {
			throw new Psd2ProviderException(
				message: 'PSD2 source "' . $sourceSlug . '" could not be resolved (register "openconnector", schema '
					. '"source"). Configure the aggregator source before using the bankfeed connector.',
				previous: $exception
			);
		}

		$data = $source->getObject();
		if (($data['type'] ?? '') !== self::SOURCE_TYPE) {
			throw new Psd2ProviderException(
				message: 'Source "' . $sourceSlug . '" is not a PSD2 aggregator source (expected type "psd2").'
			);
		}

		if (($data['isEnabled'] ?? true) === false) {
			throw new Psd2ProviderException(
				message: 'PSD2 source "' . $sourceSlug . '" is disabled. Enable it before connecting bank accounts.'
			);
		}

		return $source;
	}//end resolveSource()

	/**
	 * Select the provider binding named by `configuration.provider` (default `log`).
	 *
	 * @param array $configuration The PSD2 source's `configuration` object.
	 *
	 * @return Psd2AggregatorProviderInterface The resolved provider binding.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-aggregator-provider-abstraction-with-log-and-generic-rest-bindings-req-001
	 */
	public function resolveProvider(array $configuration): Psd2AggregatorProviderInterface {
		$provider = ($configuration['provider'] ?? 'log');
		if ($provider === 'rest') {
			return $this->restProvider;
		}

		return $this->logProvider;
	}//end resolveProvider()

	/**
	 * Store returned consent-token material through the OpenRegister
	 * credential store — never plaintext on any OR object (REQ-002/REQ-006).
	 *
	 * Fails closed: when the credential store is unavailable the token is NOT
	 * persisted anywhere and the consent finalisation aborts with an
	 * actionable configuration error — there is no plaintext fallback.
	 *
	 * @param string $token The consent/refresh token returned by the provider.
	 * @param string $connectionId The connection the token belongs to (used as the vault key).
	 *
	 * @return void
	 *
	 * @throws Psd2ProviderException When the credential store cannot broker the token.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-consent-tokens-and-aggregator-credentials-brokered-never-plaintext-req-006
	 */
	private function brokerConsentToken(string $token, string $connectionId): void {
		if ($this->storeResolver === null && class_exists(self::CREDENTIAL_STORE_RESOLVER_CLASS) === false) {
			throw new Psd2ProviderException(
				message: 'The aggregator returned consent-token material but the OpenRegister credential store is '
					. 'unavailable — failing closed (no plaintext-token fallback is permitted, ADR-007). Upgrade '
					. 'OpenRegister to a version that ships the credential broker.'
			);
		}

		try {
			$store = null;
			if ($this->storeResolver !== null) {
				$store = ($this->storeResolver)();
			}

			if ($store === null) {
				$resolverClass = self::CREDENTIAL_STORE_RESOLVER_CLASS;
				$resolver = \OCP\Server::get($resolverClass);
				$store = $resolver->resolve();
			}

			$store->put(uuid: $connectionId, secret: $token);
		} catch (Throwable $exception) {
			throw new Psd2ProviderException(
				message: 'Brokering the consent token failed — failing closed, the token was not persisted anywhere.',
				previous: $exception
			);
		}

	}//end brokerConsentToken()

	/**
	 * Find the bankfeed_connection created for a consent reference.
	 *
	 * @param string $reference The aggregator consent reference.
	 *
	 * @return ObjectEntity|null The connection, or null when none matches.
	 */
	private function findConnectionByReference(string $reference): ?ObjectEntity {
		if ($reference === '') {
			return null;
		}

		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_CONNECTION,
					'consentReference' => $reference,
				],
				'limit' => 1,
			]
		);
		$results = ($matches['results'] ?? $matches);

		if (empty($results) === true) {
			return null;
		}

		return $results[0];
	}//end findConnectionByReference()

	/**
	 * Find a bankfeed_connection by its stable connectionId.
	 *
	 * @param string $connectionId The connection identifier.
	 *
	 * @return ObjectEntity|null The connection, or null when none matches.
	 */
	private function findConnectionById(string $connectionId): ?ObjectEntity {
		if ($connectionId === '') {
			return null;
		}

		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_CONNECTION,
					'connectionId' => $connectionId,
				],
				'limit' => 1,
			]
		);
		$results = ($matches['results'] ?? $matches);

		if (empty($results) === true) {
			return null;
		}

		return $results[0];
	}//end findConnectionById()

	/**
	 * Emit one consent-lifecycle CloudEvent for a connection.
	 *
	 * Payload carries exactly `{connectionId, aggregatorSourceSlug,
	 * consentReference, consentExpiresAt}` (REQ-005) — never token material.
	 *
	 * @param string $type The CloudEvent type.
	 * @param ObjectEntity $connection The connection whose lifecycle changed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-consent-lifecycle-cloudevents-for-consumer-state-transitions-req-005
	 */
	private function emitConsentEvent(string $type, ObjectEntity $connection): void {
		$data = $connection->getObject();

		$this->eventService->emitCloudEvent(
			type: $type,
			source: '/bankfeed/connections/' . $connection->getUuid(),
			subject: (string)($data['connectionId'] ?? ''),
			data: [
				'connectionId' => ($data['connectionId'] ?? null),
				'aggregatorSourceSlug' => ($data['aggregatorSourceSlug'] ?? null),
				'consentReference' => ($data['consentReference'] ?? null),
				'consentExpiresAt' => ($data['consentExpiresAt'] ?? null),
			]
		);

	}//end emitConsentEvent()

	/**
	 * Resolve the per-source expiry warning window (days).
	 *
	 * Soft-fails to the default when the source cannot be resolved — a broken
	 * source reference must not stop the lifecycle pass.
	 *
	 * @param string $sourceSlug The connection's aggregator source slug.
	 *
	 * @return integer The warning window in days.
	 */
	private function resolveWarningDays(string $sourceSlug): int {
		try {
			$source = $this->resolveSource(sourceSlug: $sourceSlug);
			$configuration = ($source->getObject()['configuration'] ?? []);
			$days = (int)($configuration['expiryWarningDays'] ?? self::DEFAULT_EXPIRY_WARNING_DAYS);
			if ($days >= 1) {
				return $days;
			}
		} catch (Throwable) {
			// Fall through to the default.
		}

		return self::DEFAULT_EXPIRY_WARNING_DAYS;
	}//end resolveWarningDays()

	/**
	 * Resolve the aggregator vendor label for a source configuration.
	 *
	 * @param array $configuration The PSD2 source's `configuration` object.
	 *
	 * @return string The vendor label (`sandbox` for the log provider unless overridden).
	 */
	private function resolveAggregatorName(array $configuration): string {
		$explicit = (string)($configuration['aggregator'] ?? '');
		if ($explicit !== '') {
			return $explicit;
		}

		if (($configuration['provider'] ?? 'log') === 'rest') {
			return 'gocardless';
		}

		return 'sandbox';
	}//end resolveAggregatorName()

	/**
	 * Generate a RFC 4122 v4 UUID for a new connection id.
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
