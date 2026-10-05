<?php

/**
 * Builds the fields a push writes back onto the object that started it.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Synchronization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Synchronization;

use Adbar\Dot;

/**
 * Reads a synchronization's `writeBack` and fills its templates.
 *
 * `writeBack` is `{onSuccess: {field: value}, onFailure: {field: value}}`
 * (design D1 of connectors-case-system-document-delivery). A value may hold
 * `{{ path }}` placeholders read from the attempt's outcome: `response.*` (the
 * target's decoded answer), `status`, `targetId` and `error.message`. A value
 * that is exactly one placeholder keeps the type of what it reads; any other
 * text is filled as a string. A placeholder that reads nothing becomes empty.
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
 */
class OutcomeWriteBack {

	public const SUCCESS = 'onSuccess';

	public const FAILURE = 'onFailure';

	/**
	 * The longest error message written back; a case system can answer a whole page.
	 */
	private const MAX_MESSAGE = 1000;

	private const PLACEHOLDER = '/\{\{\s*([A-Za-z0-9_.\-]+)\s*\}\}/';

	/**
	 * The fields to write for one outcome, or an empty list when none are declared.
	 *
	 * @param mixed                $writeBack The synchronization's `writeBack` value.
	 * @param string               $outcome   self::SUCCESS or self::FAILURE.
	 * @param array<string, mixed> $context   The outcome: response, status, targetId, error.
	 *
	 * @return array<string, mixed> Field name to value.
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
	 */
	public function fields(mixed $writeBack, string $outcome, array $context): array {
		if (is_array($writeBack) === false || is_array($writeBack[$outcome] ?? null) === false) {
			return [];
		}

		$dot    = new Dot($context);
		$fields = [];
		foreach ($writeBack[$outcome] as $field => $value) {
			if (is_string($field) === false || $field === '') {
				continue;
			}

			$fields[$field] = $this->fill(value: $value, context: $dot);
		}

		return $fields;
	}//end fields()

	/**
	 * The message a failed answer carries: a ZGW `detail`, a `message` or a `title`, else its status.
	 *
	 * @param string|null $body   The raw answer body.
	 * @param int|null    $status The answer's status.
	 *
	 * @return string The message, at most MAX_MESSAGE characters.
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
	 */
	public function messageFromAnswer(?string $body, ?int $status): string {
		$decoded = json_decode((string)$body, true);
		if (is_array($decoded) === true) {
			foreach (['detail', 'message', 'title'] as $key) {
				if (is_string($decoded[$key] ?? null) === true && trim($decoded[$key]) !== '') {
					return $this->truncate(message: trim($decoded[$key]));
				}
			}
		}

		return 'The target answered HTTP ' . (string)($status ?? 0) . '.';
	}//end messageFromAnswer()

	/**
	 * Cut a message to MAX_MESSAGE characters.
	 *
	 * @param string $message The message.
	 *
	 * @return string The message, cut.
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
	 */
	public function truncate(string $message): string {
		return mb_substr($message, 0, self::MAX_MESSAGE);
	}//end truncate()

	/**
	 * Fill one declared value.
	 *
	 * @param mixed $value   The declared value.
	 * @param Dot   $context The outcome.
	 *
	 * @return mixed The filled value.
	 */
	private function fill(mixed $value, Dot $context): mixed {
		if (is_string($value) === false) {
			return $value;
		}

		if (preg_match('/^\s*\{\{\s*([A-Za-z0-9_.\-]+)\s*\}\}\s*$/', $value, $match) === 1) {
			return $context->get($match[1]);
		}

		return (string)preg_replace_callback(
			self::PLACEHOLDER,
			static function (array $match) use ($context): string {
				$found = $context->get($match[1]);
				if (is_scalar($found) === false) {
					return '';
				}

				return (string)$found;
			},
			$value
		);
	}//end fill()
}//end class
