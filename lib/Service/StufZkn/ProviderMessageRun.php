<?php

/**
 * Integriq StUF provider message run.
 *
 * What the request reader carries while it builds the typed event for one
 * provider message: the message element, the source id and the request's
 * referentienummer.
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
 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use SimpleXMLElement;

/**
 * One provider message on its way to a typed event.
 *
 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
 */
final class ProviderMessageRun {

	/**
	 * Constructor.
	 *
	 * @param SimpleXMLElement $message          The StUF message element.
	 * @param string           $sourceId         The source id.
	 * @param string           $referentienummer The request's referentienummer.
	 */
	public function __construct(
		public readonly SimpleXMLElement $message,
		public readonly string $sourceId,
		public readonly string $referentienummer,
	) {

	}//end __construct()
}//end class
