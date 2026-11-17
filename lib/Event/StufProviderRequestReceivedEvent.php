<?php

/**
 * Integriq StUF Provider Request Received Event.
 *
 * Lets a sibling app hand integriq, in process, a StUF provider request that
 * arrived on the sibling's own wire-contract URL (dossiq's /api/stuf/cases,
 * /persons, /zaken and /personen for one release, decision 199). Integriq's
 * listener answers it with the same status and body its own
 * /api/stuf-zkn/provider/{sourceId}/{service} route would give.
 * Unhandled (integriq disabled) means the sender should be told to retry.
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
 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-a-sibling-app-forwards-its-wire-contract-urls-for-one-release
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

/**
 * A provider request a sibling app received, for integriq to answer.
 *
 * The envelope and the answer live in {@see StufEnvelopeReceivedEvent}.
 *
 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-a-sibling-app-forwards-its-wire-contract-urls-for-one-release
 */
class StufProviderRequestReceivedEvent extends StufEnvelopeReceivedEvent {

	/**
	 * Constructor.
	 *
	 * @param string $sourceApp The app that received the envelope.
	 * @param string $rawXml    The raw request body, unchanged.
	 * @param string $sourceId  The integriq StUF-ZKN source the sibling app is configured with.
	 * @param string $service   `cases` or `persons`.
	 */
	public function __construct(
		string $sourceApp,
		string $rawXml,
		private readonly string $sourceId,
		private readonly string $service,
	) {
		parent::__construct(sourceApp: $sourceApp, rawXml: $rawXml);
	}//end __construct()

	/**
	 * The integriq source the request is for.
	 *
	 * @return string The source id.
	 *
	 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-a-sibling-app-forwards-its-wire-contract-urls-for-one-release
	 */
	public function getSourceId(): string {
		return $this->sourceId;
	}//end getSourceId()

	/**
	 * The service the request came in on.
	 *
	 * @return string `cases` or `persons`.
	 *
	 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-a-sibling-app-forwards-its-wire-contract-urls-for-one-release
	 */
	public function getService(): string {
		return $this->service;
	}//end getService()
}//end class
