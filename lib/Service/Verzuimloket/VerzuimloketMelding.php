<?php

/**
 * Integriq Verzuimloket melding.
 *
 * One melding as the envelope translator renders it: the melding kind, the
 * caller's kenmerk and the field payload.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Verzuimloket
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
 * @spec openspec/specs/verzuimloket-adapter/spec.md#requirement-req-002-outbound-envelope-translation-with-a-literal-leak-guard
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Verzuimloket;

/**
 * The values of one Verzuimloket melding.
 *
 * @spec openspec/specs/verzuimloket-adapter/spec.md#requirement-req-002-outbound-envelope-translation-with-a-literal-leak-guard
 */
final class VerzuimloketMelding {

	/**
	 * Constructor.
	 *
	 * @param string $meldingType The melding kind.
	 * @param string $kenmerk     The caller-supplied correlation id.
	 * @param array  $payload     The field payload.
	 */
	public function __construct(
		public readonly string $meldingType,
		public readonly string $kenmerk,
		public readonly array $payload,
	) {

	}//end __construct()
}//end class
