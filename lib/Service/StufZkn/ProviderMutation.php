<?php

/**
 * Integriq StUF provider mutation.
 *
 * What a kennisgeving changes: its current object, that object's fields and
 * the mutatiesoort.
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
 * The current object of one kennisgeving, its fields and its mutatiesoort.
 *
 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
 */
final class ProviderMutation {

	/**
	 * Constructor.
	 *
	 * @param SimpleXMLElement|null $object       The current object, or null when the message has none.
	 * @param array<string, string> $fields       Its leaf fields by local name.
	 * @param string                $mutatiesoort The mutatiesoort.
	 */
	public function __construct(
		public readonly ?SimpleXMLElement $object,
		public readonly array $fields,
		public readonly string $mutatiesoort,
	) {

	}//end __construct()
}//end class
