<?php

/**
 * Integriq SourceAuthApplier.
 *
 * Applies the login a source declares on its own fields (`auth`, `username`,
 * `password`, `apikey`, `authorizationHeader`) to an outbound call.
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
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/sources-declared-basic-and-apikey-auth/specs/http-call-engine/spec.md#requirement-a-source-logs-in-with-the-login-it-declares-req-sdl-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Turns a source's declared Basic or API key login into Guzzle options.
 *
 * The source form offers these fields and the register stores them, but the
 * call engine never read them, so a source set up through them called out
 * without credentials. What an operator wrote in `configuration` still wins,
 * and a source on the credential broker is left to the broker.
 *
 * @spec openspec/changes/sources-declared-basic-and-apikey-auth/specs/http-call-engine/spec.md#requirement-a-source-logs-in-with-the-login-it-declares-req-sdl-001
 */
class SourceAuthApplier {

	/**
	 * The header an API key goes in when the source names none.
	 *
	 * @var string
	 */
	public const DEFAULT_API_KEY_HEADER = 'Authorization';

	/**
	 * Apply the declared login to the call options.
	 *
	 * @param array<string,mixed> $sourceData The source object.
	 * @param array<string,mixed> $config The call options after the source configuration was merged.
	 *
	 * @return array<string,mixed> The options, with the declared login added where nothing overrides it.
	 *
	 * @spec openspec/changes/sources-declared-basic-and-apikey-auth/specs/http-call-engine/spec.md#requirement-an-explicit-login-and-the-broker-win-req-sdl-002
	 */
	public function apply(array $sourceData, array $config): array {
		$authentication = ($sourceData['configuration']['authentication'] ?? null);
		if (is_array($authentication) === true && array_key_exists('credentialRef', $authentication) === true) {
			return $config;
		}

		$strategy = strtolower(trim((string)($sourceData['auth'] ?? '')));

		if ($strategy === 'basic') {
			return $this->applyBasic(sourceData: $sourceData, config: $config);
		}

		if ($strategy === 'apikey') {
			return $this->applyApiKey(sourceData: $sourceData, config: $config);
		}

		return $config;

	}//end apply()

	/**
	 * Send the username and password as HTTP Basic credentials.
	 *
	 * @param array<string,mixed> $sourceData The source object.
	 * @param array<string,mixed> $config The call options.
	 *
	 * @return array<string,mixed> The options.
	 */
	private function applyBasic(array $sourceData, array $config): array {
		$username = (string)($sourceData['username'] ?? '');
		if ($username === '' || isset($config['auth']) === true) {
			return $config;
		}

		$config['auth'] = [$username, (string)($sourceData['password'] ?? '')];
		return $config;

	}//end applyBasic()

	/**
	 * Send the API key in the declared header.
	 *
	 * @param array<string,mixed> $sourceData The source object.
	 * @param array<string,mixed> $config The call options.
	 *
	 * @return array<string,mixed> The options.
	 */
	private function applyApiKey(array $sourceData, array $config): array {
		$key = (string)($sourceData['apikey'] ?? '');
		if ($key === '') {
			return $config;
		}

		$header = trim((string)($sourceData['authorizationHeader'] ?? ''));
		if ($header === '') {
			$header = self::DEFAULT_API_KEY_HEADER;
		}

		$headers = ($config['headers'] ?? []);
		if (is_array($headers) === false) {
			$headers = [];
		}

		foreach (array_keys($headers) as $existing) {
			if (strcasecmp((string)$existing, $header) === 0) {
				return $config;
			}
		}

		$headers[$header] = $key;
		$config['headers'] = $headers;
		return $config;

	}//end applyApiKey()

}//end class
