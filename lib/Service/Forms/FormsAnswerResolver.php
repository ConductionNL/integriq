<?php

/**
 * Integriq Forms Answer Resolver.
 *
 * Resolves a question reference (numeric id, or question text) to that
 * question's answer value(s) from a form's fetched `questions` and a
 * submission's fetched `answers` (design.md Decision 3). Never guesses on an
 * ambiguous question-text reference — mirrors `tables-bridge` REQ-001's
 * ambiguous-column-title precedent exactly (same failure posture, same
 * rationale).
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
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-answer-by-question-resolution-and-type-coercion-req-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Forms;

use OCA\Integriq\Exception\FormsConfigException;

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
	 * The refusal of a question text that matches more than one question.
	 *
	 * @var string
	 */
	private const AMBIGUOUS_TEXT = "Ambiguous question text '%s' matches multiple questions (ids: %s) — reference by question id instead of text.";

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
		$form = new FormAnswers(questions: $questions, answers: $answers);
		if ($this->isNumericReference(questionRef: $questionRef) === true) {
			return $this->resolveById(form: $form, questionId: (int)$questionRef);
		}

		return $this->resolveByText(form: $form, text: (string)$questionRef);
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
	 * The answer to one question, by id.
	 *
	 * @param FormAnswers $form       The questions and answers.
	 * @param int         $questionId The question id.
	 *
	 * @return array<int, mixed>|string|null
	 *
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-answer-by-question-resolution-and-type-coercion-req-003
	 */
	private function resolveById(FormAnswers $form, int $questionId): array|string|null {
		$matchingAnswers = array_values(
			array_filter(
				$form->answers,
				static fn (array $answer) => ((int)($answer['questionId'] ?? -1) === $questionId)
			)
		);

		$isMultiValue = $this->isMultiValueType(questions: $form->questions, questionId: $questionId);

		return $this->coerce(matchingAnswers: $matchingAnswers, isMultiValue: $isMultiValue);
	}//end resolveById()

	/**
	 * The answer to the one question with this text; ambiguous text is refused.
	 *
	 * @param FormAnswers $form The questions and answers.
	 * @param string      $text The question text.
	 *
	 * @return array<int, mixed>|string|null
	 *
	 * @throws FormsConfigException When more than one question carries the text.
	 *
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-answer-by-question-resolution-and-type-coercion-req-003
	 */
	private function resolveByText(FormAnswers $form, string $text): array|string|null {
		$matchingIds = self::questionIdsWithText(questions: $form->questions, text: $text);
		if (count($matchingIds) === 0) {
			return null;
		}

		if (count($matchingIds) > 1) {
			throw new FormsConfigException(message: sprintf(self::AMBIGUOUS_TEXT, $text, implode(', ', $matchingIds)));
		}

		return $this->resolveById(form: $form, questionId: $matchingIds[0]);
	}//end resolveByText()

	/**
	 * The ids of the questions whose text is exactly this, in order.
	 *
	 * @param array<int, mixed> $questions The form's questions.
	 * @param string            $text      The question text.
	 *
	 * @return list<int>
	 *
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-answer-by-question-resolution-and-type-coercion-req-003
	 */
	private static function questionIdsWithText(array $questions, string $text): array {
		$matchingIds = [];
		foreach ($questions as $question) {
			if (is_array($question) === true && (string)($question['text'] ?? '') === $text) {
				$matchingIds[] = (int)($question['id'] ?? 0);
			}
		}

		return $matchingIds;
	}//end questionIdsWithText()

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

		return self::singleText(matchingAnswers: $matchingAnswers);
	}//end coerce()

	/**
	 * The first answer's text for a single-value question, or null.
	 *
	 * `null` preserves "unanswered"/no-text semantics; an unexpected array
	 * shape (should not occur for a non-multi-value question) is defensively
	 * treated the same way rather than violating the declared `string` scalar
	 * return type.
	 *
	 * @param array<int, array<string, mixed>> $matchingAnswers The answers to the question.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-answer-by-question-resolution-and-type-coercion-req-003
	 */
	private static function singleText(array $matchingAnswers): ?string {
		$rawText = ($matchingAnswers[0]['text'] ?? null);
		if ($rawText === null || is_array($rawText) === true) {
			return null;
		}

		return (string)$rawText;
	}//end singleText()
}//end class
