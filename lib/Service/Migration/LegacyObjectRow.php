<?php

/**
 * One legacy row and the JSON body it is written to OpenRegister with.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Migration
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

namespace OCA\Integriq\Service\Migration;

/**
 * A legacy row as an OR object row: its uuid, timestamps and JSON body.
 *
 * @spec openspec/specs/openconnector-storage-migration/spec.md
 */
final class LegacyObjectRow {

	/**
	 * Hold one legacy row and its body.
	 *
	 * @param array<string, mixed> $row The legacy row.
	 * @param array<string, mixed> $body The JSON body built from it.
	 */
	public function __construct(
		public readonly array $row,
		public readonly array $body,
	) {
	}//end __construct()

	/**
	 * The row's uuid, or an empty string when it has none.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/openconnector-storage-migration/spec.md
	 */
	public function uuid(): string {
		return (string)($this->row['uuid'] ?? '');
	}//end uuid()

	/**
	 * The created timestamp: `created`, else `date_created`, else now.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/openconnector-storage-migration/spec.md
	 */
	public function created(): string {
		return (string)($this->row['created'] ?? $this->row['date_created'] ?? date('Y-m-d H:i:s'));
	}//end created()

	/**
	 * The updated timestamp: `updated`, else `date_modified`, else now.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/openconnector-storage-migration/spec.md
	 */
	public function updated(): string {
		return (string)($this->row['updated'] ?? $this->row['date_modified'] ?? date('Y-m-d H:i:s'));
	}//end updated()
}//end class
