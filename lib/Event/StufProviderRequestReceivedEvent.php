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
 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-a-sibling-app-forwards-its-wire-contract-urls-for-one-release
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * A provider request a sibling app received, for integriq to answer.
 *
 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-a-sibling-app-forwards-its-wire-contract-urls-for-one-release
 */
class StufProviderRequestReceivedEvent extends Event {

	/**
	 * The HTTP status integriq answered, null while unhandled.
	 *
	 * @var integer|null
	 */
	private ?int $statusCode = null;

	/**
	 * The body integriq answered.
	 *
	 * @var string
	 */
	private string $body = '';

	/**
	 * Constructor.
	 *
	 * @param string $sourceApp The app that received the envelope.
	 * @param string $rawXml    The raw request body, unchanged.
	 * @param string $sourceId  The integriq StUF-ZKN source the sibling app is configured with.
	 * @param string $service   `cases` or `persons`.
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $rawXml,
		private readonly string $sourceId,
		private readonly string $service,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The app that received the envelope.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-a-sibling-app-forwards-its-wire-contract-urls-for-one-release
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The raw request body.
	 *
	 * @return string The envelope.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-a-sibling-app-forwards-its-wire-contract-urls-for-one-release
	 */
	public function getRawXml(): string {
		return $this->rawXml;
	}//end getRawXml()

	/**
	 * The integriq source the request is for.
	 *
	 * @return string The source id.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-a-sibling-app-forwards-its-wire-contract-urls-for-one-release
	 */
	public function getSourceId(): string {
		return $this->sourceId;
	}//end getSourceId()

	/**
	 * The service the request came in on.
	 *
	 * @return string `cases` or `persons`.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-a-sibling-app-forwards-its-wire-contract-urls-for-one-release
	 */
	public function getService(): string {
		return $this->service;
	}//end getService()

	/**
	 * Record integriq's answer.
	 *
	 * @param int    $statusCode The HTTP status.
	 * @param string $body       The SOAP body.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-a-sibling-app-forwards-its-wire-contract-urls-for-one-release
	 */
	public function answer(int $statusCode, string $body): void {
		$this->statusCode = $statusCode;
		$this->body       = $body;
	}//end answer()

	/**
	 * Whether integriq answered.
	 *
	 * @return bool True once answered.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-a-sibling-app-forwards-its-wire-contract-urls-for-one-release
	 */
	public function isHandled(): bool {
		return $this->statusCode !== null;
	}//end isHandled()

	/**
	 * The HTTP status integriq answered.
	 *
	 * @return int|null The status, null while unhandled.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-a-sibling-app-forwards-its-wire-contract-urls-for-one-release
	 */
	public function getStatusCode(): ?int {
		return $this->statusCode;
	}//end getStatusCode()

	/**
	 * The body integriq answered.
	 *
	 * @return string The SOAP body, '' while unhandled.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-a-sibling-app-forwards-its-wire-contract-urls-for-one-release
	 */
	public function getBody(): string {
		return $this->body;
	}//end getBody()
}//end class
