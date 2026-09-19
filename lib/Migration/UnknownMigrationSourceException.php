<?php

/**
 * Raised when a migration names a source id no adapter answers to.
 *
 * @category Exception
 * @package  OCA\Integriq\Migration
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

namespace OCA\Integriq\Migration;

use RuntimeException;

/**
 * It fails naming the source id, and reads nothing.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#scenario-an-unknown-source-id-fails-loudly
 */
class UnknownMigrationSourceException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $sourceId The source id nothing answers to.
	 * @param array<int,string> $known Source ids that do exist.
	 */
	public function __construct(private readonly string $sourceId, array $known = []) {
		$knownText = '(none)';
		if ($known !== []) {
			$knownText = implode(', ', $known);
		}

		parent::__construct(
			sprintf(
				'No migration source adapter is registered under the id "%s". Registered ids: %s. Nothing was read.',
				$sourceId,
				$knownText
			)
		);
	}//end __construct()

	/**
	 * The source id nothing answers to.
	 *
	 * @return string Source id.
	 */
	public function getSourceId(): string {
		return $this->sourceId;
	}//end getSourceId()
}//end class
