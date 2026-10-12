<?php

/**
 * Integriq StUF Document Mutation Event.
 *
 * An edcLk01 a case system sent: create or change this document on a zaak. The
 * owning app accepts it with the document's identificatie, or refuses.
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
 * A document kennisgeving (edcLk01).
 *
 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
 */
class StufDocumentMutationEvent extends StufProviderQueryEvent {

	/**
	 * Constructor.
	 *
	 * @param string                $sourceId         The source the request came in on.
	 * @param string                $referentienummer The request's referentienummer.
	 * @param string                $mutatiesoort     The StUF mutatiesoort (T, W, V, E), '' when absent.
	 * @param array<string, string> $fields           StUF element name to value, from the object.
	 * @param array<string, mixed>  $operation        The generic document operation the message maps to.
	 */
	public function __construct(
		string $sourceId,
		string $referentienummer,
		private readonly string $mutatiesoort,
		private readonly array $fields,
		private readonly array $operation = [],
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
	 * The document's fields as the case system sent them.
	 *
	 * @return array<string, string> StUF element name to value.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 */
	public function getFields(): array {
		return $this->fields;

	}//end getFields()

	/**
	 * The generic document operation this message maps to (decision 206).
	 *
	 * The owning app carries this out and never reads the StUF names:
	 * {@see \OCA\Integriq\Service\StufZkn\StufProviderOperationMapper} lists the keys.
	 *
	 * @return array<string, mixed> The operation, `action` always set when mapped.
	 *
	 * @spec openspec/changes/stuf-provider-writes/specs/stuf-provider-receiver/spec.md#requirement-a-write-message-reaches-the-owning-app-as-a-generic-case-operation
	 */
	public function getOperation(): array {
		return $this->operation;

	}//end getOperation()

	/**
	 * Accept the kennisgeving.
	 *
	 * @param string $appId          The answering app.
	 * @param string $identification The document's identificatie after the change.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 */
	public function accept(string $appId, string $identification): void {
		$this->setAnswer(appId: $appId, payload: ['identification' => $identification]);

	}//end accept()
}//end class
