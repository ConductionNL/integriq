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

use OCA\Integriq\Service\Security\PersonalDataMasker;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;

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
	 * Constructor.
	 *
	 * @param SensitiveFieldRegistry|null $sensitiveFields The personal data field list, with an
	 *                                                     administrator's additions; the default
	 *                                                     list when null.
	 *
	 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	public function __construct(
		private readonly ?SensitiveFieldRegistry $sensitiveFields = null,
	) {

	}//end __construct()

	/**
	 * Redact one rendered body.
	 *
	 * @param string $body The rendered body.
	 * @param bool   $personalData Whether personal data is masked too; false only for a
	 *                             request kept to be sent again.
	 *
	 * @return string The body with credential material replaced.
	 *
	 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	public function redactBody(string $body, bool $personalData = true): string {
		// Personal data in the same pass (REQ-PDM-001), first, while a JSON or
		// XML body is still whole: a secret pattern can cut through its syntax.
		$redacted = $body;
		if ($personalData === true) {
			$redacted = (string)$this->registry()->maskPersonalData(value: $body);
		}

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
	 * Redact a render context, however deeply nested.
	 *
	 * @param array<string,mixed> $context The context.
	 * @param bool                $personalData Whether personal data is masked too; false only
	 *                                          for a request kept to be sent again.
	 *
	 * @return array<string,mixed> The redacted context.
	 *
	 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	public function redactContext(array $context, bool $personalData = true): array {
		$redacted = [];
		foreach ($context as $key => $value) {
			if ($this->isSecretKey(key: (string)$key) === true) {
				$redacted[$key] = self::PLACEHOLDER;
				continue;
			}

			if ($personalData === true && is_string($key) === true && $value !== null
				&& $this->registry()->isPersonalDataName(name: $key) === true
			) {
				$redacted[$key] = PersonalDataMasker::PLACEHOLDER;
				continue;
			}

			if (is_array($value) === true) {
				$redacted[$key] = $this->redactContext(context: $value, personalData: $personalData);
				continue;
			}

			if (is_string($value) === true) {
				$redacted[$key] = $this->redactBody(body: $value, personalData: $personalData);
				continue;
			}

			$redacted[$key] = $value;
		}

		return $redacted;

	}//end redactContext()

	/**
	 * A stored copy with only its personal data masked, for a record whose
	 * secrets were redacted before (REQ-PDM-001).
	 *
	 * @param array<string,mixed> $context The stored copy.
	 *
	 * @return array<string,mixed> The masked copy.
	 *
	 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
	 */
	public function maskPersonalData(array $context): array {
		$masked = $this->registry()->maskPersonalData(value: $context);
		if (is_array($masked) === false) {
			return $context;
		}

		return $masked;
	}//end maskPersonalData()

	/**
	 * The registry that knows the personal data field names.
	 *
	 * @return SensitiveFieldRegistry The registry.
	 */
	private function registry(): SensitiveFieldRegistry {
		return ($this->sensitiveFields ?? new SensitiveFieldRegistry());
	}//end registry()

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
