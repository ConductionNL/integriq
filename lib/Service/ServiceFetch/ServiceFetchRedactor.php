<?php

/**
 * Integriq Service Fetch Redactor.
 *
 * Removes a service fetch's input values from the call log line of its call:
 * from the logged URL (raw and URL-encoded), from every string in the logged
 * request, and from the logged response body, which can echo a value back.
 * A BSN passed as an input therefore never stays in the log (design D4).
 *
 * @category Service
 * @package  OCA\Integriq\Service\ServiceFetch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\ServiceFetch;

use OCA\Integriq\Service\Security\SensitiveFieldRegistry;

/**
 * Input-value redaction of a call log row.
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
 */
class ServiceFetchRedactor {

	/**
	 * The row with every value replaced by the redaction placeholder in its
	 * `request` and in its `response.body`. Other fields are left alone.
	 *
	 * @param array<string, mixed> $row    The call log row.
	 * @param list<string>         $values The values to redact, longest first.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function redact(array $row, array $values): array {
		$values = array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
		if ($values === []) {
			return $row;
		}

		$search = [];
		foreach ($values as $value) {
			$search[] = $value;
			$search[] = rawurlencode($value);
			$search[] = urlencode($value);
		}

		$search = array_values(array_unique($search));
		usort($search, static fn (string $a, string $b): int => (strlen($b) <=> strlen($a)));

		if (isset($row['request']) === true) {
			$row['request'] = $this->walk(value: $row['request'], search: $search);
		}

		if (is_array($row['response'] ?? null) === true && is_string($row['response']['body'] ?? null) === true) {
			$row['response']['body'] = $this->replace(text: $row['response']['body'], search: $search);
		}

		return $row;
	}//end redact()

	/**
	 * Redact every string inside a value.
	 *
	 * @param mixed        $value  The value.
	 * @param list<string> $search The strings to replace.
	 *
	 * @return mixed
	 */
	private function walk(mixed $value, array $search): mixed {
		if (is_string($value) === true) {
			return $this->replace(text: $value, search: $search);
		}

		if (is_array($value) === true) {
			foreach ($value as $key => $item) {
				$value[$key] = $this->walk(value: $item, search: $search);
			}
		}

		return $value;
	}//end walk()

	/**
	 * Replace the strings in one text.
	 *
	 * @param string       $text   The text.
	 * @param list<string> $search The strings to replace.
	 *
	 * @return string
	 */
	private function replace(string $text, array $search): string {
		return str_replace($search, SensitiveFieldRegistry::PLACEHOLDER, $text);
	}//end replace()
}//end class
