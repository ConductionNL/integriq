<?php

/**
 * Integriq outbound iWMO/iJW bericht run.
 *
 * The state of one OutboundMessageTranslator::translate() call while the
 * envelope is built: the document, the validated case object fields, and
 * the berichttype and referentienummer the stuurgegevens carry. Never
 * leaves OutboundMessageTranslator.
 *
 * @category Service
 * @package  OCA\Integriq\Service\IwmoIjw
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
 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-outbound-berichttype-translation-with-a-literal-leak-guard-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\IwmoIjw;

use DOMDocument;

/**
 * One outbound bericht being built.
 *
 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-outbound-berichttype-translation-with-a-literal-leak-guard-req-002
 */
final class OutboundBerichtRun {

	/**
	 * The berichttype, e.g. `Wmo303`.
	 *
	 * @var string
	 */
	public string $berichttype = '';

	/**
	 * The referentienummer of this bericht.
	 *
	 * @var string
	 */
	public string $ref = '';

	/**
	 * Constructor.
	 *
	 * @param DOMDocument $document   The envelope document.
	 * @param array       $caseObject The OR case object fields, validated present.
	 */
	public function __construct(
		public readonly DOMDocument $document,
		public readonly array $caseObject,
	) {

	}//end __construct()
}//end class
