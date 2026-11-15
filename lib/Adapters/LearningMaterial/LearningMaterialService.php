<?php

/**
 * Integriq learning material service.
 *
 * Carries out a learning material request from a sibling app: search and
 * find on the named source, the connection status and its settings. The
 * connection's settings shape every search: a switched-off source refuses,
 * and the collections switched off are left out unless the request names
 * collections itself.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\LearningMaterial;

/**
 * Learning material requests.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
 */
class LearningMaterialService {
	public const UNAVAILABLE_REASON = 'Edurep antwoordt niet';

	/**
	 * Sources declared but not available yet, with the reason.
	 *
	 * @var array<string,string>
	 */
	public const NOT_AVAILABLE = ['edusources' => 'Edusources heeft nog geen ondersteunde openbare API.'];

	/**
	 * Constructor.
	 *
	 * @param LearningMaterialSource $edurep The Edurep source.
	 * @param EdurepConnection $connection The Edurep connection.
	 */
	public function __construct(
		private readonly LearningMaterialSource $edurep,
		private readonly EdurepConnection $connection,
	) {
	}//end __construct()

	/**
	 * Carry out one request.
	 *
	 * @param array<string,mixed> $request `operation`, `source` and the operation's fields.
	 *
	 * @return array<string,mixed>|null The answer; null when `find` found nothing.
	 *
	 * @throws LearningMaterialRefusedException When the request is refused or the source did not answer.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
	 */
	public function handle(array $request): ?array {
		self::guardSource(source: (string)($request['source'] ?? LomRecordReader::SOURCE));
		$handler = ($this->handlers(request: $request)[(string)($request['operation'] ?? '')] ?? null);
		if ($handler === null) {
			throw new LearningMaterialRefusedException(refusalCode: 'unknown-operation', details: ['reason' => 'Onbekende vraag.']);
		}

		try {
			return $handler();
		} catch (SourceUnavailableException $error) {
			$details = ['reason' => self::UNAVAILABLE_REASON, 'source' => $error->source, 'lastSuccess' => $error->lastSuccess];
			throw new LearningMaterialRefusedException(refusalCode: 'source-unavailable', details: $details);
		}
	}//end handle()

	/**
	 * The filters a search runs with: switched-off collections left out and the default language, unless the request sets them.
	 *
	 * @param array<string,mixed> $filters The request's filters.
	 *
	 * @return array<string,mixed> The filters.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
	 */
	public function withSettings(array $filters): array {
		$settings = $this->connection->settings()->toArray();
		if (isset($filters['collection']) === false && isset($filters['excludeCollections']) === false) {
			$filters['excludeCollections'] = $settings['disabledCollections'];
		}

		$filters['language'] = ($filters['language'] ?? $settings['language']);

		return $filters;
	}//end withSettings()

	/**
	 * The handler of each operation.
	 *
	 * @param array<string,mixed> $request The request.
	 *
	 * @return array<string,callable(): (array<string,mixed>|null)> Handlers by operation.
	 */
	private function handlers(array $request): array {
		return [
			'search' => fn (): array => $this->search(request: $request),
			'find' => fn (): ?array => $this->find(id: (string)($request['id'] ?? '')),
			'status' => fn (): array => $this->connection->status(),
			'saveSettings' => fn (): array => $this->saveSettings(settings: (array)($request['settings'] ?? [])),
		];
	}//end handlers()

	/**
	 * Search the source.
	 *
	 * @param array<string,mixed> $request The request.
	 *
	 * @return array<string,mixed> The search result.
	 */
	private function search(array $request): array {
		$this->guardEnabled();
		$filters = $this->withSettings(filters: (array)($request['filters'] ?? []));

		return $this->edurep->search(query: (string)($request['query'] ?? ''), filters: $filters, page: (int)($request['page'] ?? 1))->toArray();
	}//end search()

	/**
	 * Find one record.
	 *
	 * @param string $id The record identifier.
	 *
	 * @return array<string,mixed>|null The record, or null.
	 */
	private function find(string $id): ?array {
		$this->guardEnabled();

		return $this->edurep->find(id: $id)?->toArray();
	}//end find()

	/**
	 * Save the settings and report the status.
	 *
	 * @param array<string,mixed> $settings The settings.
	 *
	 * @return array<string,mixed> The connection status.
	 */
	private function saveSettings(array $settings): array {
		$this->connection->settings()->save(settings: $settings);

		return $this->connection->status();
	}//end saveSettings()

	/**
	 * Refuse a search or lookup while the connection is switched off.
	 *
	 * @return void
	 *
	 * @throws LearningMaterialRefusedException When the connection is off.
	 */
	private function guardEnabled(): void {
		if ($this->connection->settings()->isEnabled() === false) {
			throw new LearningMaterialRefusedException(refusalCode: 'source-disabled', details: ['reason' => 'De koppeling met Edurep staat uit.']);
		}
	}//end guardEnabled()

	/**
	 * Refuse a source integriq does not carry.
	 *
	 * @param string $source The source's name.
	 *
	 * @return void
	 *
	 * @throws LearningMaterialRefusedException When the source is unknown or not available yet.
	 */
	private static function guardSource(string $source): void {
		if (isset(self::NOT_AVAILABLE[$source]) === true) {
			throw new LearningMaterialRefusedException(refusalCode: 'source-not-available', details: ['reason' => self::NOT_AVAILABLE[$source]]);
		}

		if ($source !== LomRecordReader::SOURCE) {
			throw new LearningMaterialRefusedException(refusalCode: 'unknown-source', details: ['reason' => 'Onbekende bron.']);
		}
	}//end guardSource()
}//end class
