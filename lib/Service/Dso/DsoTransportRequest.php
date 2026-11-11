<?php

/**
 * One request DsoClient sends to DSO-LV.
 *
 * Kept beside `DsoClient` so its dispatch takes one request instead of the
 * transport choice, the authentication block, the URL, the options and the
 * method one by one.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Dso
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
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-dsoclient-routes-through-the-mtls-transport-when-configured
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Dso;

/**
 * A DSO-LV request: where it goes, how it travels and what it carries.
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-dsoclient-routes-through-the-mtls-transport-when-configured
 */
final class DsoTransportRequest {

	/**
	 * The request headers.
	 *
	 * @var array<string, string>
	 */
	public array $headers = [];

	/**
	 * The absolute request URL.
	 *
	 * @var string
	 */
	public string $url = '';

	/**
	 * The Guzzle request options besides the headers.
	 *
	 * @var array<string, mixed>
	 */
	public array $options = [];

	/**
	 * The HTTP method: POST for messages, GET for bijlagen.
	 *
	 * @var string
	 */
	public string $method = 'POST';

	/**
	 * Constructor.
	 *
	 * @param array   $authConfig The source's `configuration.authentication` object.
	 * @param boolean $useMtls    Whether `authentication.mode=mtls` is configured.
	 */
	public function __construct(
		public readonly array $authConfig,
		public readonly bool $useMtls,
	) {

	}//end __construct()

	/**
	 * The Guzzle request options, headers first.
	 *
	 * @return array<string, mixed> The options.
	 *
	 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-dsoclient-routes-through-the-mtls-transport-when-configured
	 */
	public function guzzleOptions(): array {
		return ['headers' => $this->headers] + $this->options;

	}//end guzzleOptions()
}//end class
