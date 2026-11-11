<?php

/**
 * Integriq StUF-ZDS request.
 *
 * One StUF-ZDS message as StufZdsOutbound sends it: the source, the
 * operation, the built message and the caller's context.
 *
 * @category Service
 * @package  OCA\Integriq\Service\StufZkn
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
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

/**
 * Send one StUF-ZDS message.
 *
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
 */
final class ZdsRequest {

	/**
	 * Constructor.
	 *
	 * @param array                                     $source    The source, `id` set.
	 * @param string                                    $operation The operation.
	 * @param array{referentienummer:string,xml:string} $message   The built message.
	 * @param array                                     $context   correlationId, sourceApp, caseIdentification.
	 */
	public function __construct(
		public readonly array $source,
		public readonly string $operation,
		public readonly array $message,
		public readonly array $context,
	) {

	}//end __construct()
}//end class
