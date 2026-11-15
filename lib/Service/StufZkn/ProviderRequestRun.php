<?php

/**
 * Integriq StUF provider request run.
 *
 * The state of one parsed provider request while StufProviderReceiver
 * authenticates it, reads it, asks the owning app and renders the answer:
 * the message element and its berichttype, the fault code a refusal uses,
 * the reply stuurgegevens, the authenticated source, and the event that
 * carries the question. Never leaves StufProviderReceiver.
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
 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-provider-requests-are-authenticated-against-the-source
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use OCA\Integriq\Event\StufProviderQueryEvent;
use SimpleXMLElement;

/**
 * One provider request in progress.
 *
 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-provider-requests-are-authenticated-against-the-source
 */
final class ProviderRequestRun {

	/**
	 * The message element inside the SOAP body, null when there is none.
	 *
	 * @var SimpleXMLElement|null
	 */
	public ?SimpleXMLElement $message = null;

	/**
	 * The message's element name, empty when there is none.
	 *
	 * @var string
	 */
	public string $berichttype = '';

	/**
	 * Fo03 for a kennisgeving, Fo02 for a vraag.
	 *
	 * @var string
	 */
	public string $faultCode = '';

	/**
	 * The reply stuurgegevens.
	 *
	 * @var array
	 */
	public array $reply = [];

	/**
	 * The authenticated source, null until authentication passed.
	 *
	 * @var array|null
	 */
	public ?array $source = null;

	/**
	 * The event that carries the question to the owning app.
	 *
	 * @var StufProviderQueryEvent|null
	 */
	public ?StufProviderQueryEvent $event = null;

	/**
	 * Constructor.
	 *
	 * @param ProviderRequest  $request The request.
	 * @param SimpleXMLElement $root    Its parsed envelope.
	 */
	public function __construct(
		public readonly ProviderRequest $request,
		public readonly SimpleXMLElement $root,
	) {

	}//end __construct()
}//end class
