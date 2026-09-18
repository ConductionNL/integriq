<?php

/**
 * Integriq Caller Directory.
 *
 * Asks the configured klantinteracties binding which parties could be on a
 * number, and which cases one of them has open.
 *
 * It is a seam, and a thin one on purpose. `KlantinteractiesProviderInterface`
 * has no way to search by phone number today, so a binding that cannot answer
 * says so by returning nothing rather than by this class inventing a search.
 * The lookup that follows refuses on an empty candidate list, so an instance
 * whose binding cannot search shows "unknown caller" on every call. That is
 * correct and visible, which is what makes it safe to ship before the search
 * exists.
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

/**
 * The candidates a number could belong to, and a party's open cases.
 */
class CallerDirectory {

	/**
	 * Constructor.
	 *
	 * @param CallerSearchInterface|null $search The binding that can search by number, when one is installed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?CallerSearchInterface $search = null,
	) {
	}//end __construct()

	/**
	 * Whether this instance can look a caller up at all.
	 *
	 * Exposed so a panel can say "caller lookup is not configured" instead of
	 * showing "unknown caller" on every call and leaving an administrator to
	 * wonder which of the two it is.
	 *
	 * @return boolean True when a search binding is installed.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
	 */
	public function canSearch(): bool {
		return ($this->search !== null);

	}//end canSearch()

	/**
	 * The parties that could be on this number.
	 *
	 * @param string $e164 The caller's number.
	 * @param array $sourceConfiguration The klantinteracties source configuration.
	 *
	 * @return array<int, array<string, mixed>> The candidates, possibly empty.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
	 */
	public function candidatesFor(string $e164, array $sourceConfiguration = []): array {
		if ($this->search === null) {
			return [];
		}

		return $this->search->findPartiesByPhoneNumber(
			sourceConfiguration: $sourceConfiguration,
			e164: $e164
		);

	}//end candidatesFor()

	/**
	 * The case references this party has open.
	 *
	 * @param array $party The matched party.
	 * @param array $sourceConfiguration The klantinteracties source configuration.
	 *
	 * @return array<int, string> The case references, newest first.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
	 */
	public function openCasesFor(array $party, array $sourceConfiguration = []): array {
		if ($this->search === null) {
			return [];
		}

		return $this->search->findOpenCasesForParty(
			sourceConfiguration: $sourceConfiguration,
			party: $party
		);

	}//end openCasesFor()

}//end class
