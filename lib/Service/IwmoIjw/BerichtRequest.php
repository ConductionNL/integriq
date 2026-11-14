<?php

/**
 * One iWMO/iJW bericht as IStandardsClient posts it.
 *
 * Kept beside IStandardsClient so its dispatch takes one request instead of
 * the transport choice, the authentication block, the URL and the options
 * one by one.
 *
 * @category Service
 * @package  OCA\Integriq\Service\IwmoIjw
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
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-istandaardenclient-routes-through-the-mtls-transport-when-configured
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\IwmoIjw;

/**
 * Where a bericht is posted, whether over mTLS, and the headers and envelope it carries.
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-istandaardenclient-routes-through-the-mtls-transport-when-configured
 */
final class BerichtRequest {

	/**
	 * Whether `authentication.mode=mtls` is configured.
	 *
	 * @var boolean
	 */
	public bool $useMtls = false;

	/**
	 * The source's `configuration.authentication` object.
	 *
	 * @var array
	 */
	public array $authConfig = [];

	/**
	 * The request headers.
	 *
	 * @var array<string, string>
	 */
	public array $headers = [];

	/**
	 * The fully rendered envelope XML.
	 *
	 * @var string
	 */
	public string $envelopeXml = '';

	/**
	 * Constructor.
	 *
	 * @param string $url The absolute URL the bericht is posted to.
	 */
	public function __construct(
		public readonly string $url,
	) {

	}//end __construct()

	/**
	 * The Guzzle request options: headers, the envelope as body, no exception on an error status.
	 *
	 * @return array<string, mixed> The options.
	 *
	 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#scenario-the-rest-provider-sends-the-expected-bearer-auth-header
	 */
	public function guzzleOptions(): array {
		return [
			'headers' => $this->headers,
			'body' => $this->envelopeXml,
			'http_errors' => false,
		];
	}//end guzzleOptions()
}//end class
