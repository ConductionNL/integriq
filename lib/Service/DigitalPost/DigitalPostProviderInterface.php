<?php

/**
 * The seam every digital post binding sits behind.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\DigitalPost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\DigitalPost;

/**
 * One seam, three bindings: a log binding for development, MijnOverheid
 * Berichtenbox over the Digikoppeling transport, and Postex over REST. A
 * binding says what it needs before it is activated, so an operator learns
 * about a missing certificate at activation and not at the first letter.
 *
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-one-provider-seam-with-log-berichtenbox-and-postex-bindings-req-dpa-001
 */
interface DigitalPostProviderInterface {
	/**
	 * The provider id a source configuration names.
	 *
	 * @return string Provider id, for example `berichtenbox`.
	 */
	public function getProviderId(): string;

	/**
	 * What this binding has to be configured with.
	 *
	 * @return array<string,mixed> A JSON-schema-shaped description of the configuration.
	 */
	public function getConfigSchema(): array;

	/**
	 * Why this binding cannot be activated with the configuration it was given.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return array<int,string> The refusals, empty when the binding may be activated.
	 */
	public function activationRefusals(array $config): array;

	/**
	 * Send one message.
	 *
	 * @param array<string,mixed> $message The message: recipient, subject, body, attachments.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return DigitalPostResult What the provider answered.
	 */
	public function send(array $message, array $config = []): DigitalPostResult;

	/**
	 * Ask the provider what became of a message it took.
	 *
	 * @param string $providerReference The provider's own reference.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return DigitalPostResult The status the provider reports.
	 */
	public function status(string $providerReference, array $config = []): DigitalPostResult;

	/**
	 * Everything the provider has received for this instance since last time.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return array<int,array<string,mixed>> The inbound items, each with a sender and a document.
	 */
	public function pollInbound(array $config = []): array;
}//end interface
