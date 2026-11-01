<?php

/**
 * The state of one configuration import preview.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * The target environment's slug maps a preview reads, and the report it fills.
 *
 * @spec openspec/specs/configuration-export-import/spec.md#scenario-preview-classifies-creates-updates-and-collisions
 */
final class ConfigurationImportPreviewRun {

	/**
	 * The report: creates, updates, collisions, unresolvedReferences and credentialsNeedingReentry.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $result = [
		'creates' => [],
		'updates' => [],
		'collisions' => [],
		'unresolvedReferences' => [],
		'credentialsNeedingReentry' => [],
	];

	/**
	 * Start a preview against the target environment.
	 *
	 * @param array<string, array<string, string>> $slugMaps The target's slug => uuid map per schema.
	 */
	public function __construct(public readonly array $slugMaps) {
	}//end __construct()
}//end class
