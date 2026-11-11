<?php

/**
 * Integriq Translation Run
 *
 * One translation through one enabled translation source: the source, the
 * languages, and what the source's object says about itself.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The source and languages of one translation; built and read by TranslationService only.
 *
 * @spec openspec/specs/translation-service/spec.md#requirement-a-sibling-app-can-translate-text-through-integriq-req-trl-001
 */
final class TranslationRun {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $source The enabled translation source.
	 * @param LanguagePair $pair   The languages.
	 */
	public function __construct(
		public readonly ObjectEntity $source,
		public readonly LanguagePair $pair,
	) {

	}//end __construct()

	/**
	 * The source's slug.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/translation-service/spec.md#requirement-a-sibling-app-can-translate-text-through-integriq-req-trl-001
	 */
	public function slug(): string {
		return (string)($this->source->getObject()['slug'] ?? '');

	}//end slug()

	/**
	 * The provider protocol the source speaks: its `translationProvider`, else its slug.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/translation-service/spec.md#requirement-a-sibling-app-can-translate-text-through-integriq-req-trl-001
	 */
	public function provider(): string {
		return (string)($this->source->getObject()['configuration']['translationProvider'] ?? $this->slug());

	}//end provider()

	/**
	 * The source's display name, else its slug.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/translation-service/spec.md#requirement-a-sibling-app-can-translate-text-through-integriq-req-trl-001
	 */
	public function name(): string {
		return (string)($this->source->getObject()['name'] ?? $this->slug());

	}//end name()
}//end class
