<?php

/**
 * Integriq Language Pair
 *
 * The normalised ISO 639-1 codes one translation runs between.
 *
 * @category Service
 * @package  OCA\Integriq\Service
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
 * @spec openspec/specs/translation-service/spec.md#requirement-a-sibling-app-can-translate-text-through-integriq-req-trl-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * A source and a target language, lower-cased and trimmed.
 *
 * @spec openspec/specs/translation-service/spec.md#requirement-a-sibling-app-can-translate-text-through-integriq-req-trl-001
 */
final class LanguagePair {

	/**
	 * The text's language.
	 *
	 * @var string
	 */
	public readonly string $from;

	/**
	 * The wanted language.
	 *
	 * @var string
	 */
	public readonly string $to;

	/**
	 * Constructor.
	 *
	 * @param string $sourceLocale ISO 639-1 code of the text's language, as given.
	 * @param string $targetLocale ISO 639-1 code of the wanted language, as given.
	 */
	public function __construct(string $sourceLocale, string $targetLocale) {
		$this->from = strtolower(trim($sourceLocale));
		$this->to = strtolower(trim($targetLocale));

	}//end __construct()
}//end class
