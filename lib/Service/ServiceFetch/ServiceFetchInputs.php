<?php

/**
 * Integriq Service Fetch Inputs.
 *
 * Checks the input values an app passes to a service fetch against the
 * fetch's declared inputs, and renders the fetch's path and query with them.
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

use InvalidArgumentException;

/**
 * Input checking and template rendering for a service fetch (design D3 steps 3 and 4).
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
 */
class ServiceFetchInputs {

	/**
	 * The declared inputs' values, undeclared inputs dropped.
	 *
	 * An absent or empty optional input is left out. A required input that is
	 * absent or empty, or any input whose value is not of its declared type,
	 * is refused with an InvalidArgumentException whose message is the input's
	 * name.
	 *
	 * @param array<int, array<string, mixed>> $declared The fetch's `inputs`.
	 * @param array<string, mixed>             $given    The values the app passed.
	 *
	 * @return array<string, mixed> The values to use, by input name.
	 *
	 * @throws InvalidArgumentException Naming the first missing or wrongly typed input.
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function accepted(array $declared, array $given): array {
		$accepted = [];
		foreach ($declared as $input) {
			$name = (string)($input['name'] ?? '');
			if ($name === '') {
				continue;
			}

			$value = ($given[$name] ?? null);
			if ($value === null || $value === '') {
				if (($input['required'] ?? false) === true) {
					throw new InvalidArgumentException($name);
				}

				continue;
			}

			if ($this->hasType(value: $value, type: (string)($input['type'] ?? 'string')) === false) {
				throw new InvalidArgumentException($name);
			}

			$accepted[$name] = $value;
		}//end foreach

		return $accepted;
	}//end accepted()

	/**
	 * The path with every `{name}` placeholder replaced by its URL-encoded value.
	 *
	 * A placeholder without a value renders empty. A rendered path that could
	 * leave the source location (a scheme, a protocol-relative start or a `.`
	 * or `..` segment) is refused.
	 *
	 * @param string               $path   The path template.
	 * @param array<string, mixed> $inputs The accepted inputs.
	 *
	 * @return string The rendered path.
	 *
	 * @throws InvalidArgumentException When the rendered path leaves the source location.
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function path(string $path, array $inputs): string {
		$rendered = $this->render(template: $path, inputs: $inputs, encode: true);

		if (str_contains($rendered, '://') === true || str_starts_with($rendered, '//') === true || str_contains($rendered, '\\') === true) {
			throw new InvalidArgumentException('path');
		}

		$segments = explode('/', (string)strtok($rendered, '?'));
		foreach ($segments as $segment) {
			$decoded = rawurldecode($segment);
			if ($decoded === '.' || $decoded === '..') {
				throw new InvalidArgumentException('path');
			}
		}

		return $rendered;
	}//end path()

	/**
	 * The query with every value's placeholders replaced. Values stay unencoded:
	 * the HTTP client encodes the query. Parameters that render empty are left out.
	 *
	 * @param array<string, mixed> $query  Parameter name to template.
	 * @param array<string, mixed> $inputs The accepted inputs.
	 *
	 * @return array<string, string> The rendered query.
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function query(array $query, array $inputs): array {
		$rendered = [];
		foreach ($query as $name => $template) {
			if (is_scalar($template) === false) {
				continue;
			}

			$value = $this->render(template: (string)$template, inputs: $inputs, encode: false);
			if ($value !== '') {
				$rendered[(string)$name] = $value;
			}
		}

		return $rendered;
	}//end query()

	/**
	 * An input value as text: booleans as `true` / `false`.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function text(mixed $value): string {
		if ($value === true) {
			return 'true';
		}

		if ($value === false) {
			return 'false';
		}

		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return '';
	}//end text()

	/**
	 * Replace `{name}` placeholders in a template.
	 *
	 * @param string               $template The template.
	 * @param array<string, mixed> $inputs   The accepted inputs.
	 * @param boolean              $encode   Whether to URL-encode each value.
	 *
	 * @return string
	 */
	private function render(string $template, array $inputs, bool $encode): string {
		return (string)preg_replace_callback(
			'/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
			function (array $match) use ($inputs, $encode): string {
				$value = $this->text(value: ($inputs[$match[1]] ?? null));
				if ($encode === true) {
					return rawurlencode($value);
				}

				return $value;
			},
			$template
		);
	}//end render()

	/**
	 * Whether a value has a declared input type.
	 *
	 * @param mixed  $value The value.
	 * @param string $type  `string`, `number`, `date` or `boolean`.
	 *
	 * @return boolean
	 */
	private function hasType(mixed $value, string $type): bool {
		return match ($type) {
			'number' => (is_int($value) === true || is_float($value) === true || (is_string($value) === true && is_numeric($value) === true)),
			'boolean' => is_bool($value),
			'date' => (is_string($value) === true && $this->isDate(value: $value) === true),
			default => (is_string($value) === true || is_int($value) === true || is_float($value) === true),
		};
	}//end hasType()

	/**
	 * Whether a string is a calendar date written as YYYY-MM-DD.
	 *
	 * @param string $value The value.
	 *
	 * @return boolean
	 */
	private function isDate(string $value): bool {
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

		return $date !== false && $date->format('Y-m-d') === $value;
	}//end isDate()
}//end class
