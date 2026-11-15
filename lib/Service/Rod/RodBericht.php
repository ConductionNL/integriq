<?php

/**
 * Integriq DUO ROD bericht.
 *
 * One ROD bericht as the envelope translator renders it: the berichtsoort,
 * the caller's kenmerk, the field payload and the resolved persoonsgebonden
 * nummer.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Rod
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/rod-adapter/spec.md#scenario-a-complete-inschrijving-translates-to-a-valid-envelope
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Rod;

/**
 * The values of one ROD bericht.
 *
 * @spec openspec/specs/rod-adapter/spec.md#scenario-a-complete-inschrijving-translates-to-a-valid-envelope
 */
final class RodBericht {

	/**
	 * Constructor.
	 *
	 * @param string                             $berichtsoort The ROD berichtsoort kind.
	 * @param string                             $kenmerk      The caller-supplied correlation id.
	 * @param array                              $payload      The field payload.
	 * @param array{type: string, value: string} $number       The persoonsgebonden nummer.
	 */
	public function __construct(
		public readonly string $berichtsoort,
		public readonly string $kenmerk,
		public readonly array $payload,
		public readonly array $number,
	) {

	}//end __construct()
}//end class
