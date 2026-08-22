<?php

/**
 * Integriq DSO Verzoek Translator.
 *
 * Translates one already-parsed DSO Verzoek (the array
 * {@see \OCA\Integriq\Service\DSOParserService::parseRequest()} produces
 * — `verzoekId`, `type`, `aanvrager`, `locatie`, `activiteiten`, ...) into
 * the normalised handoff-ready fields a `dso_verzoek` OR record carries:
 * `mappedTitle`/`mappedSummary`/`mappedChannel`/`mappedPriority`. This is a
 * FIXED translator, not admin-configurable — unlike Open Formulieren (an
 * arbitrary, per-form third-party field set), a DSO Verzoek's shape is the
 * nationally standardised STAM schema `DSOParserService` already parses, so
 * there is no per-deployment "form mapping" to resolve against (see
 * design.md "Two distinct translation layers").
 *
 * LITERAL-LEAK GUARD: a Verzoek with an empty/missing `verzoekId` raises
 * {@see DsoTranslationException} BEFORE any normalised record is returned —
 * the caller MUST NEVER fabricate or guess a correlation reference (mirrors
 * `InboundReturnTranslator`'s `kenmerk` guard and `FormFieldMapper`'s
 * refuse-to-leak-the-literal-key convention).
 *
 * @category Service
 * @package  OCA\Integriq\Service\Dso
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
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-inbound-verzoek-translation-with-a-literal-leak-guard-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Dso;

use OCA\Integriq\Exception\DsoTranslationException;

/**
 * Parsed DSO Verzoek array -> normalised `dso_verzoek` handoff fields.
 *
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-inbound-verzoek-translation-with-a-literal-leak-guard-req-002
 */
class DsoRequestTranslator {

	/**
	 * Recognised Verzoek types (mirrors `DSOParserService::VALID_TYPES`).
	 *
	 * @var array<int, string>
	 */
	private const VALID_TYPES = ['aanvraag', 'melding', 'informatieverzoek', 'vooroverleg'];

	/**
	 * `type` values that map to a "medium" default priority (a full
	 * vergunningaanvraag is treated as higher-priority than a lightweight
	 * informatieverzoek/vooroverleg) — see design.md's priority table.
	 *
	 * @var array<int, string>
	 */
	private const HIGH_PRIORITY_TYPES = ['aanvraag'];

	/**
	 * The fixed `channel` value this translator always assigns — every DSO
	 * Verzoek arrives through the Omgevingsloket, never another channel.
	 *
	 * @var string
	 */
	private const CHANNEL = 'omgevingsloket';

	/**
	 * Translate one parsed DSO Verzoek into normalised handoff fields.
	 *
	 * @param array<string, mixed> $request The parsed Verzoek
	 *                                      ({@see \OCA\Integriq\Service\DSOParserService::parseRequest()}'s output).
	 *
	 * @return array{verzoekId: string, type: string, mappedTitle: string, mappedSummary: string,
	 *         mappedChannel: string, mappedPriority: string, requester: array<string, mixed>} The
	 *         normalised fields.
	 *
	 * @throws DsoTranslationException When `verzoekId` is missing/empty, or `type` is not one of
	 *                                 the recognised Verzoek types.
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#scenario-a-full-aanvraag-verzoek-translates-to-mapped
	 */
	public function translate(array $request): array {
		$requestId = trim((string)($request['verzoekId'] ?? ''));
		if ($requestId === '') {
			throw new DsoTranslationException(
				message: 'DSO Verzoek is missing verzoekId — refusing to create an unresolvable dso_verzoek record.'
			);
		}

		$type = (string)($request['type'] ?? '');
		if (in_array($type, self::VALID_TYPES, true) === false) {
			throw new DsoTranslationException(
				message: 'DSO Verzoek "' . $requestId . '" declares unrecognised type "' . $type . '".'
			);
		}

		return [
			'verzoekId' => $requestId,
			'type' => $type,
			'mappedTitle' => $this->resolveTitle(request: $request, type: $type),
			'mappedSummary' => $this->resolveSummary(request: $request),
			'mappedChannel' => self::CHANNEL,
			'mappedPriority' => $this->resolvePriority(type: $type),
			'requester' => $this->resolveRequester(request: $request),
		];

	}//end translate()

	/**
	 * Resolve the normalised title from the first activiteit's omschrijving
	 * (or `code` when no omschrijving is present), falling back to a
	 * type-based generic title when no activiteiten are present at all —
	 * this bridge never fabricates a title referencing data that is not
	 * actually on the Verzoek.
	 *
	 * @param array<string, mixed> $request The parsed Verzoek.
	 * @param string $type The Verzoek type.
	 *
	 * @return string The resolved title.
	 */
	private function resolveTitle(array $request, string $type): string {
		$activiteiten = (array)($request['activiteiten'] ?? []);
		$first = ($activiteiten[0] ?? null);

		if (is_array($first) === true) {
			$omschrijving = trim((string)($first['omschrijving'] ?? ''));
			if ($omschrijving !== '') {
				return $omschrijving;
			}

			$code = trim((string)($first['code'] ?? ''));
			if ($code !== '') {
				return $code;
			}
		}

		return 'DSO ' . $type;
	}//end resolveTitle()

	/**
	 * Resolve the normalised summary: every activiteit's omschrijving/code,
	 * comma-joined, plus the projectbeschrijving when present.
	 *
	 * @param array<string, mixed> $request The parsed Verzoek.
	 *
	 * @return string The resolved summary.
	 */
	private function resolveSummary(array $request): string {
		$activiteiten = (array)($request['activiteiten'] ?? []);
		$labels = [];
		foreach ($activiteiten as $activity) {
			if (is_array($activity) === false) {
				continue;
			}

			$label = trim((string)($activity['omschrijving'] ?? ($activity['code'] ?? '')));
			if ($label !== '') {
				$labels[] = $label;
			}
		}

		$summary = implode(', ', $labels);

		$projectbeschrijving = trim((string)($request['projectbeschrijving'] ?? ''));
		if ($projectbeschrijving !== '') {
			if ($summary !== '') {
				$summary .= ' — ';
			}

			$summary .= $projectbeschrijving;
		}

		if ($summary === '') {
			return 'Verzoek zonder activiteitomschrijving.';
		}

		return $summary;
	}//end resolveSummary()

	/**
	 * Resolve the normalised priority: `hoog` for a full aanvraag,
	 * `normaal` for every other Verzoek type — see design.md's priority
	 * table for the rationale (a lightweight informatieverzoek/vooroverleg
	 * carries no Awb beslistermijn pressure the way a vergunningaanvraag does).
	 *
	 * @param string $type The Verzoek type.
	 *
	 * @return string The resolved priority.
	 */
	private function resolvePriority(string $type): string {
		if (in_array($type, self::HIGH_PRIORITY_TYPES, true) === true) {
			return 'hoog';
		}

		return 'normaal';
	}//end resolvePriority()

	/**
	 * Resolve the requester auth context (`bsn`/`kvkNummer`) from the
	 * aanvrager block — never logged by any caller (mirrors Open
	 * Formulieren's `authContext` convention).
	 *
	 * @param array<string, mixed> $request The parsed Verzoek.
	 *
	 * @return array<string, mixed> `{bsn, kvkNummer}` (either may be null).
	 */
	private function resolveRequester(array $request): array {
		$applicant = (array)($request['aanvrager'] ?? []);

		return [
			'bsn' => ($applicant['bsn'] ?? null),
			'kvkNummer' => ($applicant['kvkNummer'] ?? null),
		];

	}//end resolveRequester()
}//end class
