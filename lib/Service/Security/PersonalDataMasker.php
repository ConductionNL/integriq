<?php

/**
 * Masks the values of declared personal data fields in a logged copy.
 *
 * A BSN, a name or an address in a request or response body is masked before
 * the body reaches the call log, a trace snapshot or an outbound message
 * record. JSON, XML (StUF) and URL query strings are read; anything else is
 * left as it is. Only the logged copy is masked: the message that is sent is
 * never passed through here.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Security;

/**
 * Walks arrays, JSON, XML and query strings and masks personal data fields by name.
 *
 * A field name matches after normalisation: lower case, an XML namespace
 * prefix dropped, and every character that is not a letter or a digit removed,
 * so `inp.bsn`, `BG:inp.bsn` and `inpBsn` are one name, as are `e-mail` and
 * `email`. Names match exactly, never as a substring: `zaaktypeNaam` is not a
 * name of a person.
 *
 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
 */
final class PersonalDataMasker {

	/**
	 * Written in place of a masked value.
	 *
	 * @var string
	 */
	public const PLACEHOLDER = '[afgeschermd]';

	/**
	 * JSON flags that write a body back without escaping what it did not escape.
	 *
	 * @var integer
	 */
	private const JSON_FLAGS = (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

	/**
	 * An XML element holding text only: its full name, its local name, attributes and the text.
	 *
	 * @var string
	 */
	private const XML_TEXT_ELEMENT = '/<((?:[A-Za-z_][\w.\-]*:)?([A-Za-z_][\w.\-]*))(\s[^<>]*)?>([^<]+)<\/\1\s*>/u';

	/**
	 * The normalised names to mask, as keys.
	 *
	 * @var array<string, true>
	 */
	private array $names;

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $names The field names to mask, in any spelling.
	 */
	public function __construct(array $names) {
		$this->names = [];
		foreach ($names as $name) {
			$normal = self::normalise(name: (string)$name);
			if ($normal !== '') {
				$this->names[$normal] = true;
			}
		}

	}//end __construct()

	/**
	 * A field name in the one spelling names are compared in.
	 *
	 * @param string $name The name, as a key, element or parameter carries it.
	 *
	 * @return string The normalised name; empty when nothing is left.
	 *
	 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	public static function normalise(string $name): string {
		$local = $name;
		$colon = strrpos($name, ':');
		if ($colon !== false) {
			$local = substr($name, ($colon + 1));
		}

		return (string)preg_replace('/[^a-z0-9]/', '', strtolower($local));
	}//end normalise()

	/**
	 * Whether a field name is declared personal data.
	 *
	 * @param string $name The name.
	 *
	 * @return boolean True when its value is masked.
	 *
	 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	public function isPersonalDataName(string $name): bool {
		return isset($this->names[self::normalise(name: $name)]);
	}//end isPersonalDataName()

	/**
	 * A value with its personal data masked.
	 *
	 * An array is walked by key; a string is read as a body.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The masked copy.
	 *
	 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	public function mask(mixed $value): mixed {
		if (is_array($value) === true) {
			return $this->maskArray(data: $value);
		}

		if (is_string($value) === true) {
			return $this->maskBody(body: $value);
		}

		return $value;
	}//end mask()

	/**
	 * An array with every personal data key's value masked, at any depth.
	 *
	 * @param array $data The array.
	 *
	 * @return array The masked copy.
	 *
	 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	public function maskArray(array $data): array {
		foreach ($data as $key => $value) {
			if (is_string($key) === true && $this->isPersonalDataName(name: $key) === true) {
				$data[$key] = $this->placeholderFor(value: $value);
				continue;
			}

			$data[$key] = $this->mask(value: $value);
		}

		return $data;
	}//end maskArray()

	/**
	 * A body with its personal data masked: JSON, XML or a URL with a query.
	 *
	 * A body in any other shape is returned as it is.
	 *
	 * @param string $body The body.
	 *
	 * @return string The masked body.
	 *
	 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	public function maskBody(string $body): string {
		if ($this->names === []) {
			return $body;
		}

		$start = ltrim($body)[0] ?? '';
		if ($start === '{' || $start === '[') {
			return $this->maskJson(body: $body);
		}

		if ($start === '<') {
			return $this->maskXml(body: $body);
		}

		return $this->maskUrl(url: $body);
	}//end maskBody()

	/**
	 * A JSON body with its personal data masked; unchanged when it is not JSON
	 * or holds no personal data, so its spacing survives.
	 *
	 * @param string $body The body.
	 *
	 * @return string The masked body.
	 *
	 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	public function maskJson(string $body): string {
		// Decoded without assoc so an empty object stays `{}` when written back.
		$decoded = json_decode($body);
		if (is_array($decoded) === false && is_object($decoded) === false) {
			return $body;
		}

		$changed = false;
		$masked = $this->maskDecoded(data: $decoded, changed: $changed);
		if ($changed === false) {
			return $body;
		}

		$encoded = json_encode($masked, self::JSON_FLAGS);
		if ($encoded === false) {
			return $body;
		}

		return $encoded;
	}//end maskJson()

	/**
	 * An XML body with the text of every personal data element masked.
	 *
	 * Only elements that hold text are masked; the rest of the document,
	 * its namespaces and its layout are left exactly as they were.
	 *
	 * @param string $body The body.
	 *
	 * @return string The masked body.
	 *
	 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	public function maskXml(string $body): string {
		$masked = preg_replace_callback(
			self::XML_TEXT_ELEMENT,
			function (array $match): string {
				if ($this->isPersonalDataName(name: $match[2]) === false || trim($match[4]) === '') {
					return $match[0];
				}

				return '<' . $match[1] . $match[3] . '>' . self::PLACEHOLDER . '</' . $match[1] . '>';
			},
			$body
		);

		if ($masked === null) {
			return $body;
		}

		return $masked;
	}//end maskXml()

	/**
	 * A URL with its personal data query parameters masked.
	 *
	 * @param string $url The URL; a string without a query is returned as it is.
	 *
	 * @return string The masked URL.
	 *
	 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	public function maskUrl(string $url): string {
		$queryStart = strpos($url, '?');
		if ($queryStart === false || preg_match('/\s/', $url) === 1) {
			return $url;
		}

		parse_str(substr($url, ($queryStart + 1)), $params);
		$masked = $this->maskArray(data: $params);
		if ($masked === $params) {
			return $url;
		}

		return substr($url, 0, $queryStart) . '?' . http_build_query($masked);
	}//end maskUrl()

	/**
	 * A decoded JSON value with its personal data masked.
	 *
	 * @param mixed   $data    The decoded value; objects stay objects.
	 * @param boolean $changed Set to true when anything was masked.
	 *
	 * @return mixed The masked value.
	 */
	private function maskDecoded(mixed $data, bool &$changed): mixed {
		if (is_object($data) === true) {
			foreach (get_object_vars($data) as $key => $value) {
				if ($this->isPersonalDataName(name: (string)$key) === true) {
					$data->$key = $this->placeholderFor(value: $value);
					$changed = true;
					continue;
				}

				$data->$key = $this->maskDecoded(data: $value, changed: $changed);
			}

			return $data;
		}

		if (is_array($data) === true) {
			foreach ($data as $index => $value) {
				$data[$index] = $this->maskDecoded(data: $value, changed: $changed);
			}
		}

		return $data;
	}//end maskDecoded()

	/**
	 * What a personal data value becomes: the placeholder, or null kept null.
	 *
	 * A whole object (an address with its parts) becomes one placeholder.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The placeholder, or null for an absent value.
	 */
	private function placeholderFor(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		return self::PLACEHOLDER;
	}//end placeholderFor()
}//end class
