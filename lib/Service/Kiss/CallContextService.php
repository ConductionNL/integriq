<?php

/**
 * Integriq Call Context Service.
 *
 * What is known about whoever is ringing: the party, and their open cases.
 *
 * Every refusal in {@see CallerLookup} is load-bearing here, and this class
 * adds one more: when no party is matched, there are NO open cases either.
 * Returning a case list beside a null caller would put somebody's cases on the
 * panel under "unknown caller", which is the wrong-person failure wearing a
 * different hat.
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

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a ringing number to a party and their open cases.
 */
class CallContextService {

	/**
	 * Constructor.
	 *
	 * @param CallerLookup $lookup Matches a number to exactly one party, or none.
	 * @param CallerDirectory $directory Asks the klantinteracties binding for candidates.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CallerLookup $lookup,
		private readonly CallerDirectory $directory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What is known about the caller on this number.
	 *
	 * @param string $e164 The caller's number in E.164, or '' when withheld.
	 * @param array $sourceConfiguration The klantinteracties source configuration.
	 *
	 * @return array{caller: (array|null), openCases: array} The context.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
	 */
	public function resolve(string $e164, array $sourceConfiguration = []): array {
		if ($this->lookup->isUsable(e164: $e164) === false) {
			return $this->unknown();
		}

		try {
			$candidates = $this->directory->candidatesFor(e164: $e164, sourceConfiguration: $sourceConfiguration);
		} catch (Throwable $e) {
			// A directory that cannot answer is an unknown caller, not a
			// failed call. The phone is still ringing.
			$this->logger->warning('[CallContextService] caller directory unavailable: '.$e->getMessage());

			return $this->unknown();
		}

		$caller = $this->lookup->match(e164: $e164, parties: $candidates);
		if ($caller === null) {
			return $this->unknown();
		}

		try {
			$openCases = $this->directory->openCasesFor(
				party: $caller,
				sourceConfiguration: $sourceConfiguration
			);
		} catch (Throwable $e) {
			// The caller is known and the cases are not. Say so by naming the
			// caller with an empty case list, rather than discarding a good
			// identification because a second lookup failed.
			$this->logger->warning('[CallContextService] open cases unavailable: '.$e->getMessage());
			$openCases = [];
		}

		return [
			'caller'    => $caller,
			'openCases' => $openCases,
		];

	}//end resolve()

	/**
	 * The context for a caller nobody could place.
	 *
	 * The case list is EMPTY, always. A case list beside a null caller is
	 * somebody's cases on the panel under "unknown caller".
	 *
	 * @return array{caller: null, openCases: array} The empty context.
	 */
	private function unknown(): array {
		return [
			'caller'    => null,
			'openCases' => [],
		];

	}//end unknown()

}//end class
