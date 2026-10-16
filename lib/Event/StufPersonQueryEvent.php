<?php

/**
 * Integriq StUF Person Query Event.
 *
 * An npsLv01 a case system sent: who is the person with this BSN. The owning app
 * answers with zero or more persons.
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
 * A person query (npsLv01).
 *
 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
 */
class StufPersonQueryEvent extends StufProviderQueryEvent {

	/**
	 * Constructor.
	 *
	 * @param string $sourceId         The source the request came in on.
	 * @param string $referentienummer The request's referentienummer.
	 * @param string $bsn              The BSN asked about.
	 */
	public function __construct(
		string $sourceId,
		string $referentienummer,
		private readonly string $bsn,
	) {
		parent::__construct(sourceId: $sourceId, referentienummer: $referentienummer);

	}//end __construct()

	/**
	 * The BSN asked about.
	 *
	 * @return string The BSN.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 */
	public function getBsn(): string {
		return $this->bsn;

	}//end getBsn()

	/**
	 * Answer with the matching persons.
	 *
	 * @param string $appId   The answering app.
	 * @param array  $persons Each person keyed inp.bsn, geslachtsnaam, voorvoegselGeslachtsnaam, voornamen, geboortedatum, geslachtsaanduiding.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 */
	public function answerPersons(string $appId, array $persons): void {
		$this->setAnswer(appId: $appId, payload: ['persons' => array_values($persons)]);

	}//end answerPersons()
}//end class
