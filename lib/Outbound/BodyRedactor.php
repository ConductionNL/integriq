<?php

/**
 * Integriq BodyRedactor.
 *
 * Redaction runs before the write, never on read. A rendered message body can
 * carry whatever its render context carried, and a secret that reaches
 * storage has already leaked however carefully the screen hides it
 * afterwards. Mirrors the snapshot redaction of `execution-trace` REQ-003,
 * which is what makes storing a body acceptable at all.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound;

/**
 * Removes credential material from a body before it is stored.
 *
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-the-sent-message-and-its-real-recipients-are-readable-behind-their-own-permission-req-ocl-002
 */
class BodyRedactor {

	/**
	 * What a redacted value is replaced with.
	 *
	 * @var string
	 */
	public const PLACEHOLDER = '[redacted]';

	/**
	 * Context keys whose value never reaches storage.
	 *
	 * @var array<int,string>
	 */
	private const SECRET_KEYS = [
		'password',
		'passwd',
		'secret',
		'clientsecret',
		'token',
		'accesstoken',
		'refreshtoken',
		'apikey',
		'api_key',
		'authorization',
		'privatekey',
		'bsn',
	];

	/**
	 * Patterns that name a secret inside free text.
	 *
	 * @var array<int,string>
	 */
	private const TEXT_PATTERNS = [
		'/\b(Bearer)\s+[A-Za-z0-9\._\-]{8,}/i',
		'/\b(Basic)\s+[A-Za-z0-9\+\/=]{8,}/i',
		'/\b(api[_\-]?key|apikey|token|secret|password|wachtwoord)\s*[:=]\s*("?[^\s"<]{4,}"?)/i',
		'/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
	];

	/**
	 * A BSN-shaped number inside free text: nine digits, optionally split by
	 * one separator each, not part of a longer run of digits.
	 *
	 * @var string
	 */
	private const BSN_PATTERN = '/(?<!\d)\d(?:[.\s_\-]?\d){8}(?!\d)/';

	/**
	 * Redact one rendered body.
	 *
	 * @param string $body The rendered body.
	 *
	 * @return string The body with credential material replaced.
	 */
	public function redactBody(string $body): string {
		$redacted = $body;
		foreach (self::TEXT_PATTERNS as $pattern) {
			$redacted = (string)preg_replace_callback(
				$pattern,
				static function (array $matches): string {
					if (count($matches) > 2) {
						return $matches[1] . ': ' . self::PLACEHOLDER;
					}

					if (count($matches) === 2) {
						return $matches[1] . ' ' . self::PLACEHOLDER;
					}

					return self::PLACEHOLDER;
				},
				$redacted
			);
		}

		return $redacted;

	}//end redactBody()

	/**
	 * Redact free text bound for a service outside Nextcloud, such as an
	 * error sink. Removes the credential material {@see redactBody()}
	 * removes, and every BSN-shaped number: nine digits, alone or with a
	 * single dot, space, dash or underscore between them. A longer run of
	 * digits, such as a timestamp, stays.
	 *
	 * @param string $text The text, for instance a log message.
	 *
	 * @return string The text with credentials and BSN-shaped numbers replaced.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
	 */
	public function redactText(string $text): string {
		$redacted = $this->redactBody(body: $text);

		return (string)preg_replace(self::BSN_PATTERN, self::PLACEHOLDER, $redacted);

	}//end redactText()

	/**
	 * Redact a render context, however deeply nested.
	 *
	 * @param array<string,mixed> $context The context.
	 *
	 * @return array<string,mixed> The redacted context.
	 */
	public function redactContext(array $context): array {
		$redacted = [];
		foreach ($context as $key => $value) {
			if ($this->isSecretKey(key: (string)$key) === true) {
				$redacted[$key] = self::PLACEHOLDER;
				continue;
			}

			if (is_array($value) === true) {
				$redacted[$key] = $this->redactContext(context: $value);
				continue;
			}

			if (is_string($value) === true) {
				$redacted[$key] = $this->redactBody(body: $value);
				continue;
			}

			$redacted[$key] = $value;
		}

		return $redacted;

	}//end redactContext()

	/**
	 * Whether a context key names a secret.
	 *
	 * @param string $key The key.
	 *
	 * @return bool True when the value must not be stored.
	 */
	private function isSecretKey(string $key): bool {
		$normalised = strtolower(str_replace(['-', '_', ' '], '', $key));
		foreach (self::SECRET_KEYS as $secret) {
			if (str_contains($normalised, str_replace('_', '', $secret)) === true) {
				return true;
			}
		}

		return false;

	}//end isSecretKey()

}//end class
