<?php

/**
 * Integriq CTI (telephony) Provider Interface.
 *
 * The narrow seam every phone system reaches integriq through. A PBX posts
 * call events to one endpoint; the provider for that source turns the vendor's
 * shape into the one shape the rest of this app knows about, and says whether
 * the request was really from the PBX.
 *
 * A new phone system is added by implementing this interface, never by editing
 * CtiController or CallContextService. Mirrors
 * {@see KlantinteractiesProviderInterface}, PeppolAccessPointProviderInterface
 * and SmsProviderInterface.
 *
 * Note what a provider does NOT do: it never looks a caller up, never
 * dispatches an event, and never writes a klantcontact. It normalises and it
 * verifies. Everything a vendor could get wrong about who the caller is stays
 * on this side of the seam.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Kiss
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
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Kiss;

use OCA\Integriq\Exception\KissProviderException;

/**
 * A telephony binding: verify an inbound PBX request, and normalise its call
 * events into the shape the KCC panel is fed from.
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */
interface CtiProviderInterface {
	/**
	 * Stable machine identifier for this binding (e.g. `log`, `webhook`).
	 *
	 * Selected at runtime via the CTI source's `configuration.provider` field.
	 *
	 * @return string The provider identifier.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function getProviderId(): string;

	/**
	 * The JSON Schema describing this provider's `configuration` object.
	 *
	 * @return array<string, mixed> A JSON Schema (object) fragment.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function getConfigSchema(): array;

	/**
	 * Whether this request really came from the phone system this source names.
	 *
	 * Returns a plain boolean rather than throwing, because the caller answers
	 * 401 either way and an unverified request must be indistinguishable from
	 * an unknown source: a stack trace, a different status or a slower answer
	 * all tell somebody probing the endpoint which sources exist.
	 *
	 * @param array $sourceConfiguration The CTI source's `configuration` object.
	 * @param array $headers The inbound request headers, keys lower-cased.
	 * @param string $rawBody The request body exactly as received, before decoding.
	 *
	 * @return boolean True when the request is genuine.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function verify(array $sourceConfiguration, array $headers, string $rawBody): bool;

	/**
	 * Turn one vendor payload into zero or more normalised call events.
	 *
	 * Zero is a real answer: a PBX that posts keep-alives, or an event kind
	 * this integration does not care about, is not an error. Refusing the
	 * request for it would make the PBX retry a payload that will never be
	 * wanted.
	 *
	 * Each normalised event carries:
	 *  - `kind`: one of `ringing`, `answered`, `ended`, `transferred`
	 *  - `callId`: the PBX's own call identifier, stable across the call's kinds
	 *  - `callerNumber`: the caller in E.164, or '' when the PBX withheld it
	 *  - `agentId`: the agent the call is for, or ''
	 *  - `at`: when it happened, ISO 8601
	 *  - `durationSeconds`: on `ended`, how long the call lasted
	 *
	 * @param array $sourceConfiguration The CTI source's `configuration` object.
	 * @param array $payload The decoded vendor payload.
	 *
	 * @return array<int, array<string, mixed>> The normalised events, possibly empty.
	 *
	 * @throws KissProviderException When the payload is shaped in a way this provider cannot read at all.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function normalize(array $sourceConfiguration, array $payload): array;
}//end interface
