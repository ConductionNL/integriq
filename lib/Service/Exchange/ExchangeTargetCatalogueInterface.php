<?php

/**
 * Contract of the ExchangeTargetCatalogue.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Exchange
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Exchange;

/**
 * Public operations of {@see ExchangeTargetCatalogue} that other components call.
 */
interface ExchangeTargetCatalogueInterface {
	/**
	 * Every target id, in catalogue order.
	 *
	 * @return array<int, string> The ids.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function ids(): array;

	/**
	 * Whether a target id is known.
	 *
	 * @param string $target The target id.
	 *
	 * @return bool True when known.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function has(string $target): bool;

	/**
	 * Whether a target accepts a direction.
	 *
	 * @param string $target    The target id.
	 * @param string $direction export, import or sync.
	 *
	 * @return bool True when the target lists the direction.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function supportsDirection(string $target, string $direction): bool;

	/**
	 * The display label of a target, or the id itself when unknown.
	 *
	 * @param string $target The target id.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model
	 */
	public function label(string $target): string;

	/**
	 * The whole catalogue as rows.
	 *
	 * @return array<int, array{id: string, label: string, directions: array<int, string>, adapter: string}> The rows.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model
	 */
	public function all(): array;
}
