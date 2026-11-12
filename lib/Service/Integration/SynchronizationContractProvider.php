<?php

/**
 * Synchronization contract integration provider.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Exposes Integriq SyncContract objects as "leaves" on the OR objects they
 * synchronise. Registered with OR's IntegrationRegistry at app boot (see
 * {@see \OCA\Integriq\Service\ServiceRegistrar::boot()}). When a user opens any
 * OR object (in opencatalogi, decidesk, integriq itself, or any fleet
 * app), the object's sidebar shows a "Synced from" leaf with the SyncContract
 * summary — making the cross-app sync origin visible everywhere.
 *
 * Storage strategy: 'query-time'. No parallel link table is needed —
 * SyncContract objects already live in OR storage (chain B/C cutover);
 * we query them at request time filtering on `targetId = $objectId`.
 *
 * Read-only: contracts are managed by the sync engine (SynchronizationService),
 * not by end users. get/create/update/delete inherit the
 * AbstractIntegrationProvider default which throws NotImplementedException.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec GH issue #824
 * @ref  Local ADR-005 (Source/Sync/Contract triad)
 * @ref  Local ADR-015 (Configuration export/import — slug translation)
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Integration;

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IL10N;

/**
 * Exposes Integriq SyncContract objects as integration leaves on OR objects.
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 */
class SynchronizationContractProvider extends AbstractIntegrationProvider {

	/**
	 * Register slug under which openconnector schemas live in OR.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'integriq';

	/**
	 * Schema slug for the synchronization contract objects.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'synchronization_contract';


	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object service used to query sync contracts.
	 * @param IAppConfig $appConfig App config used to check the chain-C cutover flag.
	 * @param IL10N $l10n Translator for user-facing labels and messages.
	 * @param WriteBackConflictReader $writeBack Reads a refused write-back on the object.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l10n,
		private readonly WriteBackConflictReader $writeBack,
	) {

	}//end __construct()

	/**
	 * Get the provider id.
	 *
	 * @return string The provider identifier.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	public function getId(): string {
		return 'sync-contract';
	}//end getId()

	/**
	 * Get the user-facing label.
	 *
	 * @return string The translated label.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	public function getLabel(): string {
		return $this->l10n->t('Synced from');
	}//end getLabel()

	/**
	 * Get the icon identifier used by the OR sidebar.
	 *
	 * @return string The icon identifier.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	public function getIcon(): string {
		return 'SyncOutline';
	}//end getIcon()

	/**
	 * Get the optional group identifier.
	 *
	 * @return string|null The group identifier.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	public function getGroup(): ?string {
		return 'workflow';
	}//end getGroup()

	/**
	 * Get the app id this provider requires.
	 *
	 * @return string|null The required app id.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	public function getRequiredApp(): ?string {
		return 'integriq';
	}//end getRequiredApp()

	/**
	 * Get the storage strategy for this provider.
	 *
	 * @return string The storage strategy identifier.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	public function getStorageStrategy(): string {
		return 'query-time';
	}//end getStorageStrategy()

	/**
	 * List SyncContract leaves for an OR object.
	 *
	 * Finds every SyncContract whose `targetId === $objectId` and
	 * returns a lightweight summary for sidebar display.
	 *
	 * @param string $register The OR register slug.
	 * @param string $schema The OR schema slug.
	 * @param string $objectId The OR object id being viewed.
	 * @param array $filters Optional filters with `_limit` and `_page` for pagination.
	 *
	 * @return array The list of contract summaries.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		if ($this->isEnabled() === false) {
			return [];
		}

		$rows = $this->contractRows(objectId: $objectId, filters: $filters);

		// Only an object a synchronization wrote is read for a refused
		// write-back: this list runs on every sidebar of every app.
		$listing = new ContractListing();
		$listing->writeBackConflict = ($rows !== [] && $this->writeBack->hasConflict(register: $register, schema: $schema, objectId: $objectId) === true);

		return array_map(
			fn ($contract): array => $this->row(contract: $contract, listing: $listing),
			$rows
		);

	}//end list()

	/**
	 * The contracts naming an object as their target, one page of them.
	 *
	 * @param string $objectId The OR object id being viewed.
	 * @param array $filters Optional filters with `_limit` and `_page` for pagination.
	 *
	 * @return array The contracts; empty when none can be read.
	 */
	private function contractRows(string $objectId, array $filters): array {
		$matches = $this->findContracts(config: $this->contractQuery(objectId: $objectId, filters: $filters));

		$rows = $matches['results'] ?? $matches;
		if (is_array($rows) === false) {
			return [];
		}

		return $rows;
	}//end contractRows()

	/**
	 * The page a list asks for: 50 contracts from the start unless `_limit` / `_page` say otherwise.
	 *
	 * @param array $filters Optional filters with `_limit` and `_page`.
	 *
	 * @return array{limit:int,offset:int}
	 */
	private function pageOf(array $filters): array {
		$limit = 50;
		if (isset($filters['_limit']) === true) {
			$limit = (int)$filters['_limit'];
		}

		$offset = 0;
		if (isset($filters['_page']) === true) {
			$offset = (max((int)$filters['_page'], 1) - 1) * $limit;
		}

		return ['limit' => $limit, 'offset' => $offset];
	}//end pageOf()

	/**
	 * The contract query for one target and page: filter on the target id only.
	 *
	 * @param string $objectId The OR object id being viewed.
	 * @param array $filters Optional filters with `_limit` and `_page` for pagination.
	 *
	 * @return array{filters:array{targetId:string},limit:int,offset:int} The findAll() config.
	 */
	private function contractQuery(string $objectId, array $filters): array {
		$page = $this->pageOf(filters: $filters);

		return ['filters' => ['targetId' => $objectId], 'limit' => $page['limit'], 'offset' => $page['offset']];
	}//end contractQuery()

	/**
	 * Query the contract schema.
	 *
	 * @param array{filters:array{targetId:string},limit:int,offset:int} $config The findAll() config.
	 *
	 * @return mixed The query answer; an empty list when the query fails.
	 */
	private function findContracts(array $config): mixed {
		// Pre-set the register/schema context, then filter by targetId only.
		// Passing 'register'/'schema' *inside* `filters` sets the context but
		// ALSO leaks them as object-property filters — slug strings compared
		// against the numeric register/schema columns — which silently matches
		// nothing. Setting context via setRegister()/setSchema() and keeping
		// `filters` to `targetId` alone is what actually returns the contracts.
		//
		// Resilience (AD-23): this leaf loads on EVERY Nextcloud page and its
		// list endpoint is called from EVERY app's OR sidebar. When the
		// `synchronization_contract` schema is declared in the register JSON but
		// not yet MAPPED into the `openconnector` register on this instance
		// (a lagging/forced-import state — see openregister#2075), setSchema()/
		// findAll() throws, which would surface as a 500 fleet-wide. Degrade to
		// an empty result instead so the sidebar renders the quiet "not created
		// by a synchronization" state rather than a broken tab. The register
		// import (occ) restores the real contracts without a code change.
		try {
			return $this->objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::SCHEMA_SLUG)
				->findAll(config: $config);
		} catch (\Throwable $e) {
			return [];
		}
	}//end findContracts()

	/**
	 * One contract as a sidebar row.
	 *
	 * @param mixed           $contract The contract entity or array.
	 * @param ContractListing $listing  The listing: the name memo and the write-back state.
	 *
	 * @return array The row.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function row(mixed $contract, ContractListing $listing): array {
		$data = $this->contractData(contract: $contract);
		$data['name'] = $this->resolveSynchronizationName(
			synchronizationId: (string)($data['body']['synchronizationId'] ?? null),
			cache: $listing->synchronizationNames
		);

		return $this->card(data: $data) + $this->provenance(data: $data, listing: $listing);
	}//end row()

	/**
	 * A contract's body and uuid, from an entity or an array.
	 *
	 * @param mixed $contract The contract entity or array.
	 *
	 * @return array{body:array,uuid:string}
	 */
	private function contractData(mixed $contract): array {
		// ObjectEntity exposes getObject() as a real method but getUuid()
		// only via the Nextcloud Entity __call magic — so method_exists()
		// is false for it. Call getUuid() directly inside the object branch
		// rather than gating it behind method_exists (which would fall
		// through to `$contract['uuid']` and fatal on the object).
		if (is_object($contract) === true && method_exists($contract, 'getObject') === true) {
			return ['body' => $contract->getObject(), 'uuid' => (string)$contract->getUuid()];
		}

		return ['body' => (array)($contract['object'] ?? $contract), 'uuid' => (string)($contract['uuid'] ?? '')];
	}//end contractData()

	/**
	 * The generic-card display keys (CnIntegrationCard reads title / subtitle / url).
	 *
	 * Title is the human sync name; subtitle summarises the last sync; url
	 * deep-links into the Integriq synchronization detail page.
	 *
	 * @param array{body:array,uuid:string,name:string} $data The contract and its synchronization name.
	 *
	 * @return array The id, title, subtitle and url.
	 */
	private function card(array $data): array {
		$body = $data['body'];

		return [
			'id' => $data['uuid'],
			'title' => $data['name'],
			'subtitle' => $this->buildSubtitle(lastSynced: ($body['targetLastSynced'] ?? null), lastAction: ($body['targetLastAction'] ?? null)),
			'url' => $this->buildSyncUrl(synchronizationId: (string)($body['synchronizationId'] ?? null)),
		];
	}//end card()

	/**
	 * The raw provenance fields, for the bespoke "Synced from" component and any programmatic consumer.
	 *
	 * @param array{body:array,uuid:string,name:string} $data The contract and its synchronization name.
	 * @param ContractListing $listing The listing's write-back state.
	 *
	 * @return array The provenance keys.
	 */
	private function provenance(array $data, ContractListing $listing): array {
		$body = $data['body'];

		return [
			'synchronizationId' => $body['synchronizationId'] ?? null,
			'synchronizationName' => $data['name'],
			'originId' => $body['originId'] ?? null,
			'originHash' => $body['originHash'] ?? null,
			'targetLastAction' => $body['targetLastAction'] ?? null,
			'targetLastSynced' => $body['targetLastSynced'] ?? null,
			'sourceLastChecked' => $body['sourceLastChecked'] ?? null,
			// The connected system refused the last local change; the
			// object keeps the edit (zgw-connectors-for-dossiq D4).
			'writeBackConflict' => $listing->writeBackConflict,
		];
	}//end provenance()

	/**
	 * Resolve a synchronization's human-readable name from its id.
	 *
	 * Queries the `openconnector/synchronization` OR object and reads its
	 * `name` field. Falls back to a short-id label when the object can't
	 * be found (deleted sync, permission, etc.). Memoised per list() call
	 * via the by-reference $cache.
	 *
	 * @param string $synchronizationId The synchronization uuid.
	 * @param array<string,string> $cache By-ref per-call memo.
	 *
	 * @return string The display name.
	 */
	private function resolveSynchronizationName(string $synchronizationId, array &$cache): string {
		if ($synchronizationId === '') {
			return $this->l10n->t('Unknown synchronization');
		}

		if (isset($cache[$synchronizationId]) === true) {
			return $cache[$synchronizationId];
		}

		$name = $this->l10n->t('Synchronization %s', [substr($synchronizationId, 0, 8)]);
		try {
			$sync = $this->objectService->find(
				id: $synchronizationId,
				register: self::REGISTER_SLUG,
				schema: 'synchronization'
			);
			if (is_object($sync) === true && method_exists($sync, 'getObject') === true) {
				$syncBody = $sync->getObject();
				if (empty($syncBody['name']) === false) {
					$name = (string)$syncBody['name'];
				}
			}
		} catch (\Throwable $e) {
			// Keep the short-id fallback — a missing sync is non-fatal here.
		}

		$cache[$synchronizationId] = $name;
		return $name;
	}//end resolveSynchronizationName()

	/**
	 * Build the human subtitle line summarising the last sync.
	 *
	 * @param string|null $lastSynced ISO timestamp of the last target sync.
	 * @param string|null $lastAction The last action (create/update/delete).
	 *
	 * @return string The subtitle (may be empty when nothing is known).
	 */
	private function buildSubtitle(?string $lastSynced, ?string $lastAction): string {
		if (empty($lastSynced) === true) {
			if ($lastAction !== null && $lastAction !== '') {
				return (string)$lastAction;
			}

			return '';
		}

		$date = substr((string)$lastSynced, 0, 10);
		if ($lastAction !== null && $lastAction !== '') {
			return $this->l10n->t('Last synced %1$s · %2$s', [$date, (string)$lastAction]);
		}

		return $this->l10n->t('Last synced %s', [$date]);
	}//end buildSubtitle()

	/**
	 * Build a deep-link into the Integriq synchronization detail page.
	 *
	 * @param string $synchronizationId The synchronization uuid.
	 *
	 * @return string|null The SPA deep-link, or null when no id.
	 */
	private function buildSyncUrl(string $synchronizationId): ?string {
		if ($synchronizationId === '') {
			return null;
		}

		return '/index.php/apps/integriq/synchronizations/' . $synchronizationId;
	}//end buildSyncUrl()

	/**
	 * Health descriptor.
	 *
	 * The provider needs the chain-B/C OR-cutover to have completed for
	 * SyncContract objects to exist in OR storage.
	 *
	 * @return array The health descriptor.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	public function health(): array {
		if ($this->isEnabled() === false) {
			return [
				'status' => 'unavailable',
				'authStatus' => 'configured',
				'message' => $this->l10n->t(
					'The storage migration has not run on this instance yet. The "Synced from" panel appears once occ upgrade has run it.'
				),
			];
		}

		return [
			'status' => 'ok',
			'authStatus' => 'configured',
			'message' => null,
		];

	}//end health()

	/**
	 * Whether the provider is enabled on this instance.
	 *
	 * The provider is available once the integriq chain-C cutover has
	 * materialised SyncContract objects in OR storage. That happens when
	 * {@see \OCA\Integriq\Migration\Version2Date20260908000000} flips
	 * `integriq.storage_migrated` to `'true'`.
	 *
	 * @return bool True when the storage migration has run, false otherwise.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	public function isEnabled(): bool {
		return $this->appConfig->getAppValueString('storage_migrated', 'false') === 'true';
	}//end isEnabled()
}//end class
