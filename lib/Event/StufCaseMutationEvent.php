<?php

/**
 * Integriq StUF Case Mutation Event.
 *
 * A zakLk01 a case system sent: create, change or end this zaak (mutatiesoort T, W,
 * V or E). The owning app accepts it with the zaak's identificatie, or refuses.
 *
 * @category Event
 * @package  OCA\Integriq\Event
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
 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

/**
 * A zaak kennisgeving (zakLk01).
 *
 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
 */
class StufCaseMutationEvent extends StufProviderQueryEvent {

	/**
	 * Constructor.
	 *
	 * @param string                $sourceId         The source the request came in on.
	 * @param string                $referentienummer The request's referentienummer.
	 * @param string                $mutatiesoort     The StUF mutatiesoort (T, W, V, E), '' when absent.
	 * @param array<string, string> $fields           StUF element name to value, from the object.
	 */
	public function __construct(
		string $sourceId,
		string $referentienummer,
		private readonly string $mutatiesoort,
		private readonly array $fields,
	) {
		parent::__construct(sourceId: $sourceId, referentienummer: $referentienummer);

	}//end __construct()

	/**
	 * The StUF mutatiesoort.
	 *
	 * @return string T, W, V or E; '' when absent.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 */
	public function getMutatiesoort(): string {
		return $this->mutatiesoort;

	}//end getMutatiesoort()

	/**
	 * The zaak's fields as the case system sent them.
	 *
	 * @return array<string, string> StUF element name to value.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 */
	public function getFields(): array {
		return $this->fields;

	}//end getFields()

	/**
	 * Accept the kennisgeving.
	 *
	 * @param string $appId          The answering app.
	 * @param string $identification The zaak's identificatie after the change.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 */
	public function accept(string $appId, string $identification): void {
		$this->setAnswer(appId: $appId, payload: ['identification' => $identification]);

	}//end accept()
}//end class
