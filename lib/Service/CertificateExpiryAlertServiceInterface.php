<?php

/**
 * Contract of the CertificateExpiryAlertService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/integriq
 *
 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/connection-run-monitoring/spec.md#requirement-an-expiring-certificate-opens-an-alert-req-crun-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTimeImmutable;

/**
 * Public operations of {@see CertificateExpiryAlertService} that other components call.
 */
interface CertificateExpiryAlertServiceInterface {
	/**
	 * Open the alerts due now and clear those of a replaced certificate.
	 *
	 * @param DateTimeImmutable $now The moment of the check.
	 *
	 * @return array{opened: int, cleared: int}
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/connection-run-monitoring/spec.md#requirement-an-expiring-certificate-opens-an-alert-req-crun-008
	 */
	public function evaluate(DateTimeImmutable $now): array;
}
