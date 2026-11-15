<?php

/**
 * Integriq StUF-ZKN case kennisgeving request.
 *
 * What one outbound `zakLk01` kennisgeving is built from: the OR/ZGW zaak
 * fields, the verwerkingssoort, and the zender and ontvanger organisatie
 * codes. Built by {@see OutboundNotificationTranslator::translate()} for one
 * call.
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
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-outbound-zaklk01-kennisgeving-translation-with-a-literal-leak-guard-req-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

/**
 * One zaak kennisgeving to render.
 *
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-outbound-zaklk01-kennisgeving-translation-with-a-literal-leak-guard-req-003
 */
final class CaseKennisgeving {

	/**
	 * This bridge's own organisatie code (`stuurgegevens.zender`).
	 *
	 * @var string
	 */
	public string $zender = '';

	/**
	 * The subscribed StUF consumer's organisatie code (`stuurgegevens.ontvanger`).
	 *
	 * @var string
	 */
	public string $ontvanger = '';

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $case The OR/ZGW zaak object fields.
	 * @param string $processingKind `T` (create), `W` (update/status change), or `V` (vervallen).
	 */
	public function __construct(
		public readonly array $case,
		public readonly string $processingKind,
	) {
	}//end __construct()
}//end class
