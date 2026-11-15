<?php

/**
 * Integriq FormAnswers.
 *
 * One Forms submission's questions and answers, as an answer is looked up in
 * them.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Forms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Forms;

/**
 * Value object for a form's questions and a submission's answers (ADR-011).
 *
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-answer-by-question-resolution-and-type-coercion-req-003
 */
final class FormAnswers {

	/**
	 * Constructor.
	 *
	 * @param array<int, mixed> $questions The form's questions.
	 * @param array<int, mixed> $answers   The submission's answers.
	 */
	public function __construct(
		public readonly array $questions,
		public readonly array $answers,
	) {
	}//end __construct()
}//end class
