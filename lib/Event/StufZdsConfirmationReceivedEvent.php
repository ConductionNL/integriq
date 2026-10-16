<?php

/**
 * Integriq StUF-ZDS Confirmation Received Event.
 *
 * Lets a sibling app hand integriq, in process, a confirmation envelope that
 * arrived on the sibling's own wire-contract URL (dossiq's /api/stuf/inbound
 * for one release, decision 193). Integriq's listener answers it with the
 * same status and body its own /api/stuf-zkn/confirmations route would give.
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
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * A confirmation envelope a sibling app received, for integriq to conclude.
 *
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
 */
class StufZdsConfirmationReceivedEvent extends Event {

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
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $rawXml,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The app that received the envelope.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The raw request body.
	 *
	 * @return string The envelope.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
	 */
	public function getRawXml(): string {
		return $this->rawXml;
	}//end getRawXml()

	/**
	 * Record integriq's answer.
	 *
	 * @param int    $statusCode The HTTP status.
	 * @param string $body       The SOAP body.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
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
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
	 */
	public function isHandled(): bool {
		return $this->statusCode !== null;
	}//end isHandled()

	/**
	 * The HTTP status integriq answered.
	 *
	 * @return int|null The status, null while unhandled.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
	 */
	public function getStatusCode(): ?int {
		return $this->statusCode;
	}//end getStatusCode()

	/**
	 * The body integriq answered.
	 *
	 * @return string The SOAP body, '' while unhandled.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
	 */
	public function getBody(): string {
		return $this->body;
	}//end getBody()
}//end class
