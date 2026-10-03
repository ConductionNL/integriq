<?php

/**
 * Integriq exchange error code catalogue.
 *
 * Resolves an exchange rejection's code to a label through the seeded
 * `learniq-exchange-error-codes-*` mapping rows.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Exchange
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Exchange;

use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use Psr\Log\LoggerInterface;

/**
 * Code to label lookup over the exchange error code catalogues.
 *
 * The catalogues are `mapping` rows used as code tables (design D7): each
 * `mapping` value is `{label, labelEn, category, severity}`. They are read
 * here and never handed to MappingService. The stored row wins, so an
 * administrator's edit counts; the shipped fragment is the fallback when
 * the row has not been imported yet.
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-007-the-vocabulary-ships-as-integriq-seed-rows
 */
class ExchangeErrorCodeCatalogue {

	/**
	 * Slug prefix of every catalogue row.
	 *
	 * @var string
	 */
	public const SLUG_PREFIX = 'learniq-exchange-error-codes-';

	/**
	 * The catalogue of codes the runner raises itself.
	 *
	 * @var string
	 */
	public const RUNNER_CATALOGUE = 'integriq';

	/**
	 * The shipped fragment, read when a row is not stored yet.
	 *
	 * @var string
	 */
	private const FRAGMENT_PATH = __DIR__ . '/../../Settings/register.d/learniq-exchange-jobs.json';

	/**
	 * Loaded catalogues keyed by catalogue name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $catalogues = [];

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService OpenRegister object access.
	 * @param LoggerInterface $logger        Logger for lookups that fail.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Resolve a code for a target.
	 *
	 * Looks in the target's catalogue, then in the runner's, and falls back to
	 * the bare code so an uncatalogued code is still shown.
	 *
	 * @param string $target The exchange target id.
	 * @param string $code   The error code.
	 *
	 * @return array{label: string, labelEn: string, category: string, severity: string} The entry.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-007-the-vocabulary-ships-as-integriq-seed-rows
	 */
	public function resolve(string $target, string $code): array {
		foreach ([$target, self::RUNNER_CATALOGUE] as $catalogue) {
			$entries = $this->catalogue(name: $catalogue);
			$entry = ($entries[$code] ?? null);
			if (is_array($entry) === true) {
				return [
					'label' => (string)($entry['label'] ?? $code),
					'labelEn' => (string)($entry['labelEn'] ?? $code),
					'category' => (string)($entry['category'] ?? ''),
					'severity' => (string)($entry['severity'] ?? 'blocking'),
				];
			}
		}

		return ['label' => $code, 'labelEn' => $code, 'category' => '', 'severity' => 'blocking'];

	}//end resolve()

	/**
	 * Load one catalogue: the stored row first, the shipped fragment second.
	 *
	 * @param string $name The catalogue name (a target id or `integriq`).
	 *
	 * @return array<string, mixed> Code to entry.
	 */
	private function catalogue(string $name): array {
		if (isset($this->catalogues[$name]) === true) {
			return $this->catalogues[$name];
		}

		$slug = self::SLUG_PREFIX . $name;
		$entries = $this->storedCatalogue(slug: $slug);
		if ($entries === null) {
			$entries = $this->shippedCatalogue(slug: $slug);
		}

		$this->catalogues[$name] = $entries;
		return $entries;

	}//end catalogue()

	/**
	 * Read a catalogue row from OpenRegister.
	 *
	 * @param string $slug The mapping slug.
	 *
	 * @return array<string, mixed>|null The code table, or null when not stored.
	 */
	private function storedCatalogue(string $slug): ?array {
		try {
			$matches = $this->objectService->findAll(
				config: [
					'filters' => ['register' => 'integriq', 'schema' => 'mapping', 'slug' => $slug],
					'limit' => 1,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $exception) {
			$this->logger->warning(
				'[ExchangeErrorCodeCatalogue] could not read catalogue ' . $slug . ': ' . $exception->getMessage()
			);
			return null;
		}

		foreach (($matches['results'] ?? $matches) as $row) {
			$mapping = ($row->getObject()['mapping'] ?? null);
			if (is_array($mapping) === true) {
				return $mapping;
			}
		}

		return null;

	}//end storedCatalogue()

	/**
	 * Read a catalogue row from the shipped fragment.
	 *
	 * @param string $slug The mapping slug.
	 *
	 * @return array<string, mixed> The code table, empty when absent.
	 */
	private function shippedCatalogue(string $slug): array {
		if (is_file(self::FRAGMENT_PATH) === false) {
			return [];
		}

		$content = file_get_contents(self::FRAGMENT_PATH);
		if ($content === false) {
			return [];
		}

		$fragment = json_decode($content, true);
		foreach (($fragment['components']['objects'] ?? []) as $object) {
			if (($object['@self']['slug'] ?? '') === $slug && is_array($object['mapping'] ?? null) === true) {
				return $object['mapping'];
			}
		}

		return [];

	}//end shippedCatalogue()
}//end class
