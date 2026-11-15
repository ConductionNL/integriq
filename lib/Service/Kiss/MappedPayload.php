<?php

/**
 * Integriq mapped telephony payload.
 *
 * One vendor webhook payload together with the field mapping that says
 * where in it each normalised call field is found.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Kiss;

/**
 * A payload and its field mapping.
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */
final class MappedPayload {

	/**
	 * Constructor.
	 *
	 * @param array                $payload The vendor payload.
	 * @param array<string,string> $mapping Normalised field => dot path in the payload.
	 */
	public function __construct(
		public readonly array $payload,
		public readonly array $mapping,
	) {

	}//end __construct()

	/**
	 * The dot path of a normalised field: the mapped one, or the field's own name.
	 *
	 * @param string $field The normalised field.
	 *
	 * @return string The path.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function pathOf(string $field): string {
		return (string) ($this->mapping[$field] ?? $field);

	}//end pathOf()
}//end class
