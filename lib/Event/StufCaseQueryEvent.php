<?php

/**
 * Integriq StUF Case Query Event.
 *
 * A zakLv01 a case system sent: which zaken match these criteria. The owning app
 * answers with zero or more cases (zero is an answer: it looked and found none).
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
 * A case query (zakLv01).
 *
 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
 */
class StufCaseQueryEvent extends StufProviderQueryEvent {

	/**
	 * Constructor.
	 *
	 * @param string                $sourceId         The source the request came in on.
	 * @param string                $referentienummer The request's referentienummer.
	 * @param array<string, string> $criteria         StUF-ZKN element name to value (identificatie, omschrijving, startdatum).
	 *                                                The receiver refuses a query with no criterion, but the NAMES and values
	 *                                                are the sender's: an app answering the event must treat them as
	 *                                                caller-controlled input and match only the names it supports.
	 */
	public function __construct(
		string $sourceId,
		string $referentienummer,
		private readonly array $criteria,
	) {
		parent::__construct(sourceId: $sourceId, referentienummer: $referentienummer);

	}//end __construct()

	/**
	 * The query criteria.
	 *
	 * @return array<string, string> StUF-ZKN element name to value.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 */
	public function getCriteria(): array {
		return $this->criteria;

	}//end getCriteria()

	/**
	 * Answer with the matching cases.
	 *
	 * @param string $appId The answering app.
	 * @param array  $cases Each case keyed zaakidentificatie, omschrijving, startdatum, einddatum, zaaktype, status.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 */
	public function answerCases(string $appId, array $cases): void {
		$this->setAnswer(appId: $appId, payload: ['cases' => array_values($cases)]);

	}//end answerCases()
}//end class
