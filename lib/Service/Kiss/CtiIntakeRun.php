<?php

/**
 * Integriq CtiIntakeRun.
 *
 * The CTI source one intake runs for: its id and its configuration.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Kiss;

/**
 * Value object for one CTI intake run (ADR-011).
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */
final class CtiIntakeRun {

	/**
	 * Constructor.
	 *
	 * @param string               $sourceId      The CTI source id.
	 * @param array<string, mixed> $configuration The source's configuration.
	 */
	public function __construct(
		public readonly string $sourceId,
		public readonly array $configuration,
	) {
	}//end __construct()
}//end class
