<?php

/**
 * OpenConnector Forms Answer Resolver.
 *
 * Resolves a question reference (numeric id, or question text) to that
 * question's answer value(s) from a form's fetched `questions` and a
 * submission's fetched `answers` (design.md Decision 3). Never guesses on an
 * ambiguous question-text reference — mirrors `tables-bridge` REQ-001's
 * ambiguous-column-title precedent exactly (same failure posture, same
 * rationale).
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Forms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-answer-by-question-resolution-and-type-coercion-req-003
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Forms;

use OCA\OpenConnector\Exception\FormsConfigException;

/**
 * Answer-by-question resolution, type-aware coercion, and an ambiguity guard.
 *
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-answer-by-question-resolution-and-type-coercion-req-003
 */
class FormsAnswerResolver {

	/**
	 * Question types (`Constants::ANSWER_TYPE_*` on nextcloud/forms,
	 * discovery.md Finding 2) whose answer resolves to an ARRAY of every
	 * matching answer row's `text` — every other type resolves to a single
	 * scalar.
	 *
	 * @var array<int, string>
	 */
	private const MULTI_VALUE_TYPES = ['multiple', 'multiple_unique'];

	/**
	 * Resolve a question reference to its answer value(s).
	 *
	 * @param array $questions The form's fetched `questions`, each
	 *                         `{id, text, name, type}`
	 *                         (FormsClientInterface::getForm()).
	 * @param array $answers The submission's fetched `answers`, each
	 *                       `{id, questionId, questionName, text}`
	 *                       (FormsClientInterface::getSubmission()).
	 * @param int|string $questionRef A numeric question id (int, or a numeric string),
	 *                                or a question TEXT string.
	 *
	 * @return array|string|null An array for a `multiple`/`multiple_unique`-type question
	 *                           (zero, one, or many entries); a single scalar (or `null` when unanswered)
	 *                           for every other type; `null` when a text reference matches no question.
	 *
	 * @throws FormsConfigException When a text reference matches two or more questions.
	 *
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-answer-by-question-resolution-and-type-coercion-req-003
	 */
	public function resolve(array $questions, array $answers, int|string $questionRef): array|string|null {
		if ($this->isNumericReference(questionRef: $questionRef) === true) {
			return $this->resolveById(questions: $questions, answers: $answers, questionId: (int)$questionRef);
		}

		return $this->resolveByText(questions: $questions, answers: $answers, text: (string)$questionRef);
	}//end resolve()

	/**
	 * Whether a question reference should be treated as a numeric question id.
	 *
	 * @param int|string $questionRef The raw question reference.
	 *
	 * @return bool
	 */
	private function isNumericReference(int|string $questionRef): bool {
		if (is_int($questionRef) === true) {
			return true;
		}

		return ($questionRef !== '' && ctype_digit($questionRef) === true);
	}//end isNumericReference()

	/**
	 * Resolve directly against `answers[].questionId` — always unambiguous
	 * (`questionId` is the Forms DB primary key).
	 *
	 * @param array $questions The form's fetched questions (for type-driven coercion).
	 * @param array $answers The submission's fetched answers.
	 * @param int $questionId The numeric question id.
	 *
	 * @return array|string|null
	 *
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-answer-by-question-resolution-and-type-coercion-req-003
	 */
	private function resolveById(array $questions, array $answers, int $questionId): array|string|null {
		$matchingAnswers = array_values(
			array_filter(
				$answers,
				static fn (array $answer) => ((int)($answer['questionId'] ?? -1) === $questionId)
			)
		);

		$isMultiValue = $this->isMultiValueType(questions: $questions, questionId: $questionId);

		return $this->coerce(matchingAnswers: $matchingAnswers, isMultiValue: $isMultiValue);
	}//end resolveById()

	/**
	 * Resolve a question-TEXT reference via the form's `questions[].text` index.
	 * Exactly one match resolves via {@see resolveById}; zero matches resolve
	 * to `null`; two or more matches is a hard config error — never a guess.
	 *
	 * @param array $questions The form's fetched questions.
	 * @param array $answers The submission's fetched answers.
	 * @param string $text The question text to resolve.
	 *
	 * @return array|string|null
	 *
	 * @throws FormsConfigException When two or more questions share this exact text.
	 *
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-answer-by-question-resolution-and-type-coercion-req-003
	 */
	private function resolveByText(array $questions, array $answers, string $text): array|string|null {
		$matchingIds = [];
		foreach ($questions as $question) {
			if (is_array($question) === false) {
				continue;
			}

			if ((string)($question['text'] ?? '') === $text) {
				$matchingIds[] = (int)($question['id'] ?? 0);
			}
		}

		if (count($matchingIds) === 0) {
			return null;
		}

		if (count($matchingIds) > 1) {
			throw new FormsConfigException(
				message: sprintf(
					"Ambiguous question text '%s' matches multiple questions (ids: %s) — reference by question id instead of text.",
					$text,
					implode(', ', $matchingIds)
				)
			);
		}

		return $this->resolveById(questions: $questions, answers: $answers, questionId: $matchingIds[0]);
	}//end resolveByText()

	/**
	 * Whether the given question id is a `multiple`/`multiple_unique`-type
	 * question, per the form's fetched question list. Defaults to `false`
	 * (scalar coercion) when the question id is not present in `$questions`
	 * — a numeric reference is resolved directly against the answers even
	 * without a matching question entry (design.md Decision 3 step 1).
	 *
	 * @param array $questions The form's fetched questions.
	 * @param int $questionId The numeric question id.
	 *
	 * @return bool
	 */
	private function isMultiValueType(array $questions, int $questionId): bool {
		foreach ($questions as $question) {
			if (is_array($question) === false) {
				continue;
			}

			if ((int)($question['id'] ?? -1) === $questionId) {
				return in_array((string)($question['type'] ?? ''), self::MULTI_VALUE_TYPES, true);
			}
		}

		return false;
	}//end isMultiValueType()

	/**
	 * Coerce the matching answer rows per the question's multi-value-ness
	 * (design.md Decision 3 step 3).
	 *
	 * @param array $matchingAnswers The answer rows matching a single questionId.
	 * @param bool $isMultiValue Whether the question is `multiple`/`multiple_unique`-typed.
	 *
	 * @return array|string|null
	 */
	private function coerce(array $matchingAnswers, bool $isMultiValue): array|string|null {
		if ($isMultiValue === true) {
			return array_values(
				array_map(
					static fn (array $answer) => ($answer['text'] ?? null),
					$matchingAnswers
				)
			);
		}

		if (count($matchingAnswers) === 0) {
			return null;
		}

		$rawText = ($matchingAnswers[0]['text'] ?? null);
		if ($rawText === null || is_array($rawText) === true) {
			// `null` preserves "unanswered"/no-text semantics; an unexpected
			// array shape (should not occur for a non-multi-value question)
			// is defensively treated the same way rather than violating the
			// declared `string` scalar return type.
			return null;
		}

		return (string)$rawText;
	}//end coerce()
}//end class
