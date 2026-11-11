<?php

/**
 * Integriq Objecten record write.
 *
 * One write through the Objecten API facade, as the create, replace and
 * partial-update routes hand it to the shared write path: the objecttype,
 * the object (null on a create), the submitted record, the token's
 * principal, the API base, and the action the change is announced with.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Objecten
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Objecten;

/**
 * Write one Objecten record.
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */
final class RecordWrite {

	/**
	 * Constructor.
	 *
	 * @param string               $type      The objecttype.
	 * @param string|null          $uuid      The object, null on a create.
	 * @param array<string, mixed> $body      The submitted record.
	 * @param string               $principal The token's principal.
	 * @param string               $baseUrl   The API base.
	 * @param string               $action    The announced action.
	 */
	public function __construct(
		public readonly string $type,
		public readonly ?string $uuid,
		public readonly array $body,
		public readonly string $principal,
		public readonly string $baseUrl,
		public readonly string $action,
	) {

	}//end __construct()
}//end class
