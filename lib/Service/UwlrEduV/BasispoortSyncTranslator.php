<?php

/**
 * Integriq Basispoort Sync Translator.
 *
 * Translates a `basispoort` sync payload (`eckId`, `schoolBrin`,
 * `ssoAudience`) — PO pupil/group/staff export plus an SSO hand-off token
 * for method/publisher content, per `uwlr-eduv-basispoort-contract`'s
 * `direction: sync` seed — into a Basispoort-style XML envelope.
 *
 * LITERAL-LEAK GUARD: a missing/empty required field raises
 * UwlrEduVTranslationException BEFORE any XML is built.
 *
 * @category Service
 * @package  OCA\Integriq\Service\UwlrEduV
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
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\UwlrEduV;

use OCA\Integriq\Exception\UwlrEduVTranslationException;
use OCA\Integriq\Service\Stuf\ExportEnvelope;
use OCA\Integriq\Service\Stuf\StufLiteralLeakGuard;

/**
 * Basispoort sync payload -> Basispoort XML envelope.
 *
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
 */
class BasispoortSyncTranslator {

	/**
	 * Required fields for every Basispoort sync payload.
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED_FIELDS = ['eckId', 'schoolBrin', 'ssoAudience'];

	/**
	 * Constructor.
	 *
	 * @param StufLiteralLeakGuard $leakGuard Shared literal-leak scan.
	 */
	public function __construct(
		private readonly StufLiteralLeakGuard $leakGuard = new StufLiteralLeakGuard(),
	) {

	}//end __construct()

	/**
	 * Translate a Basispoort sync payload into a wire envelope.
	 *
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param array $payload The field payload — `eckId`, `schoolBrin`, `ssoAudience`.
	 *
	 * @return string The fully rendered envelope XML.
	 *
	 * @throws UwlrEduVTranslationException When a required field is missing/empty, or the
	 *                                      rendered envelope still carries an unresolved template marker.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#scenario-a-complete-basispoort-sync-payload-carries-the-sso-audience
	 */
	public function translate(string $kenmerk, array $payload): string {
		if (trim($kenmerk) === '') {
			throw new UwlrEduVTranslationException(message: 'A Basispoort sync requires a non-empty kenmerk (correlation id).');
		}

		$guard = new ExportPayloadGuard(leakGuard: $this->leakGuard, envelopeLabel: 'a Basispoort sync');
		$guard->assertRequiredFields(fields: self::REQUIRED_FIELDS, payload: $payload);

		$envelope = new ExportEnvelope(rootName: 'BasispoortSync', kenmerk: $kenmerk);
		$envelope->stampMessageTime();
		$envelope->addBody(fields: self::REQUIRED_FIELDS, payload: $payload);
		$xml = $envelope->toXml();
		$guard->assertNoUnresolvedPlaceholder(xml: $xml);

		return $xml;
	}//end translate()
}//end class
