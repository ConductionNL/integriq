<?php

/**
 * Integriq exchange target catalogue.
 *
 * The fourteen data exchange targets another app's exchange jobs may name,
 * with the directions each accepts and the adapter family behind it.
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

/**
 * The exchange target vocabulary (contract.md, "Targets").
 *
 * Kept in code, like GatewayCatalogue, because the dispatcher's handled set
 * and the job schema's enum must agree with it; the fragment test asserts
 * the enum equals ids().
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
 */
class ExchangeTargetCatalogue {

	/**
	 * Target id to {label, directions, adapter}.
	 *
	 * @var array<string, array{label: string, directions: array<int, string>, adapter: string}>
	 */
	private const TARGETS = [
		'bron-rod' => ['label' => 'DUO ROD', 'directions' => ['export'], 'adapter' => 'rod'],
		'oso' => ['label' => 'OSO', 'directions' => ['export', 'import'], 'adapter' => 'oso'],
		'leerplicht' => ['label' => 'DUO Verzuimloket', 'directions' => ['export'], 'adapter' => 'verzuimloket'],
		'surfconext' => ['label' => 'SURFconext', 'directions' => ['sync'], 'adapter' => ''],
		'hr' => ['label' => 'HR system', 'directions' => ['import', 'export'], 'adapter' => ''],
		'swv' => ['label' => 'Samenwerkingsverband', 'directions' => ['export'], 'adapter' => 'swv'],
		'ooapi-catalog' => ['label' => 'OOAPI catalogue', 'directions' => ['export'], 'adapter' => ''],
		'timetable-import' => ['label' => 'Timetable import', 'directions' => ['import'], 'adapter' => 'roster'],
		'migration-import' => ['label' => 'Migration import', 'directions' => ['import'], 'adapter' => 'migration'],
		'lvs-results' => ['label' => 'LVS results', 'directions' => ['import'], 'adapter' => 'lvs'],
		'uwlr' => ['label' => 'UWLR', 'directions' => ['export', 'import'], 'adapter' => 'uwlr-eduv'],
		'edu-v' => ['label' => 'Edu-V', 'directions' => ['export'], 'adapter' => 'uwlr-eduv'],
		'basispoort' => ['label' => 'Basispoort', 'directions' => ['sync'], 'adapter' => 'uwlr-eduv'],
		'entree-content' => ['label' => 'Entree content', 'directions' => ['sync'], 'adapter' => 'uwlr-eduv'],
	];

	/**
	 * Every target id, in catalogue order.
	 *
	 * @return array<int, string> The ids.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function ids(): array {
		return array_keys(self::TARGETS);

	}//end ids()

	/**
	 * Whether a target id is known.
	 *
	 * @param string $target The target id.
	 *
	 * @return bool True when known.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function has(string $target): bool {
		return isset(self::TARGETS[$target]);

	}//end has()

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
	public function supportsDirection(string $target, string $direction): bool {
		if ($this->has(target: $target) === false) {
			return false;
		}

		return in_array($direction, self::TARGETS[$target]['directions'], true);

	}//end supportsDirection()

	/**
	 * The display label of a target, or the id itself when unknown.
	 *
	 * @param string $target The target id.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model
	 */
	public function label(string $target): string {
		return (self::TARGETS[$target]['label'] ?? $target);

	}//end label()

	/**
	 * The whole catalogue as rows.
	 *
	 * @return array<int, array{id: string, label: string, directions: array<int, string>, adapter: string}> The rows.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model
	 */
	public function all(): array {
		$rows = [];
		foreach (self::TARGETS as $targetId => $entry) {
			$rows[] = [
				'id' => $targetId,
				'label' => $entry['label'],
				'directions' => $entry['directions'],
				'adapter' => $entry['adapter'],
			];
		}

		return $rows;

	}//end all()
}//end class
