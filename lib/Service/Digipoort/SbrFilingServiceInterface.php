<?php

/**
 * Integriq SBR Filing Service Interface.
 *
 * The entry points the filing-request listener and the status sweep job use,
 * so neither depends on SbrFilingService itself.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Digipoort
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
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Digipoort;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Stores and delivers SBR filings and sweeps their statuses.
 *
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md
 */
interface SbrFilingServiceInterface {
	/**
	 * CloudEvent type of a filing request.
	 *
	 * @var string
	 */
	public const EVENT_TYPE_REQUESTED = 'nl.conduction.sbr.filing.requested';

	/**
	 * Store a filing request as one filing and deliver it.
	 *
	 * @param array<string,mixed> $eventData The request's CloudEvent `data`.
	 *
	 * @return ObjectEntity|null The filing, or null when the request lacks a required field.
	 *
	 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-filing-request-becomes-one-delivery-req-dps-002
	 */
	public function handleRequested(array $eventData): ?ObjectEntity;

	/**
	 * Retry queued deliveries and poll the status of delivered filings.
	 *
	 * @return array{retried:int,polled:int,changed:int} What the sweep did.
	 *
	 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-every-status-change-is-reported-back-req-dps-003
	 */
	public function sweep(): array;
}//end interface
