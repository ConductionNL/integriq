<?php

/**
 * Refuses a synchronization run whose source needs a signature the credential broker cannot make.
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
 *
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-each-vendor-is-read-on-a-schedule-req-fedg-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Synchronization;

/**
 * Reads `sourceConfig.brokerSigning` before a run fetches anything.
 *
 * Some APIs only answer signed requests: Amazon API Gateway wants AWS
 * Signature Version 4 on every call. The signature needs the secret key, and
 * ADR-064 keeps that key inside OpenRegister's credential broker, so the
 * broker has to sign; integriq never does. A synchronization names the scheme
 * it needs, and while the broker does not offer it the run stops before a
 * single request is sent, saying what it waits for.
 *
 * The broker announces the schemes it signs in the constant
 * `CredentialBrokerService::SIGNING_SCHEMES`. Until OpenRegister ships that
 * constant with `aws-sigv4` in it, every run that needs the scheme is refused.
 *
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-each-vendor-is-read-on-a-schedule-req-fedg-002
 */
class BrokerSigningGuard {

	/**
	 * The sourceConfig key that names the signing scheme a run needs.
	 */
	public const KEY = 'brokerSigning';

	/**
	 * The broker constant that lists the schemes it signs with.
	 */
	public const BROKER_SCHEMES_CONSTANT = 'OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService::SIGNING_SCHEMES';

	/**
	 * What each known scheme is called in a message.
	 */
	private const SCHEME_NAMES = ['aws-sigv4' => 'AWS Signature Version 4'];

	/**
	 * Constructor.
	 *
	 * @param list<string>|null $brokerSchemes The schemes the broker signs with; null reads them from the broker.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?array $brokerSchemes=null,
	) {

	}//end __construct()

	/**
	 * Why the run may not start, as a message for the run log.
	 *
	 * @param array<string, mixed> $sourceConfig The synchronization's source configuration.
	 *
	 * @return string|null The refusal, or null when the run may start.
	 *
	 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-each-vendor-is-read-on-a-schedule-req-fedg-002
	 */
	public function refusal(array $sourceConfig): ?string {
		if (array_key_exists(self::KEY, $sourceConfig) === false) {
			return null;
		}

		$scheme = $sourceConfig[self::KEY];
		if (is_string($scheme) === false || trim($scheme) === '') {
			return 'Canceled: sourceConfig.' . self::KEY
				. ' must name a signing scheme, for example aws-sigv4. Nothing was fetched or written.';
		}

		$scheme = trim($scheme);
		if (in_array($scheme, $this->schemes(), true) === true) {
			return null;
		}

		return sprintf(
			'Canceled: the credential broker cannot sign %s yet, so this source cannot be called. '
			. 'This synchronization waits on OpenRegister to offer the %s scheme. Nothing was fetched or written.',
			(self::SCHEME_NAMES[$scheme] ?? $scheme),
			$scheme
		);
	}//end refusal()

	/**
	 * The signing schemes the broker offers.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-each-vendor-is-read-on-a-schedule-req-fedg-002
	 */
	private function schemes(): array {
		if ($this->brokerSchemes !== null) {
			return $this->brokerSchemes;
		}

		if (defined(self::BROKER_SCHEMES_CONSTANT) === false) {
			return [];
		}

		$schemes = constant(self::BROKER_SCHEMES_CONSTANT);
		if (is_array($schemes) === false) {
			return [];
		}

		return array_values(array_filter($schemes, 'is_string'));
	}//end schemes()
}//end class
