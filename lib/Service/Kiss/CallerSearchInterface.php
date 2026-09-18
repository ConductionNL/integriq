<?php

/**
 * Integriq Caller Search Interface.
 *
 * The one thing a klantinteracties binding must be able to do for the KCC
 * panel, kept OFF `KlantinteractiesProviderInterface` deliberately.
 *
 * That interface is implemented by every binding, and adding a method to it
 * would force the log sandbox and the REST client to answer a question neither
 * was written for. A binding that can search by number implements this as
 * well; one that cannot simply does not, and the panel says "unknown caller"
 * on every call, which is true.
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
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Kiss;

use OCA\Integriq\Exception\KissProviderException;

/**
 * A binding that can find a party by phone number.
 */
interface CallerSearchInterface {
	/**
	 * Every party carrying this number as a digital address.
	 *
	 * Returns CANDIDATES, not an answer. Deciding which of them is the caller,
	 * and refusing when it cannot be decided, is {@see CallerLookup}'s job and
	 * must not be second-guessed by a binding returning only its favourite.
	 *
	 * @param array $sourceConfiguration The klantinteracties source configuration.
	 * @param string $e164 The caller's number in E.164.
	 *
	 * @return array<int, array<string, mixed>> The candidate parties.
	 *
	 * @throws KissProviderException When the backend is unreachable or misconfigured.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
	 */
	public function findPartiesByPhoneNumber(array $sourceConfiguration, string $e164): array;

	/**
	 * The case references this party has open.
	 *
	 * @param array $sourceConfiguration The klantinteracties source configuration.
	 * @param array $party The matched party.
	 *
	 * @return array<int, string> The case references, newest first.
	 *
	 * @throws KissProviderException When the backend is unreachable or misconfigured.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
	 */
	public function findOpenCasesForParty(array $sourceConfiguration, array $party): array;
}//end interface
