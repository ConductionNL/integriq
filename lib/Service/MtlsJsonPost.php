<?php

/**
 * MTLS JSON Post
 *
 * POSTs a JSON payload to a DSO endpoint, with the client certificate for
 * mutual TLS when one is configured. Shared by the DSO-LV status push and the
 * DSO-SWF adviesverzoek.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;

/**
 * One JSON POST over a fresh client, optionally with a client certificate.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-11
 */
final class MtlsJsonPost {

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService Nextcloud HTTP client factory.
	 * @param string|null    $certPath      Client certificate path for mTLS, or null for none.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly ?string $certPath,
	) {

	}//end __construct()

	/**
	 * POST the payload as JSON.
	 *
	 * @param string              $uri     The endpoint.
	 * @param array<string,mixed> $payload The JSON body.
	 *
	 * @return IResponse The response.
	 *
	 * @throws \Exception When the client cannot reach the endpoint.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-11
	 */
	public function post(string $uri, array $payload): IResponse {
		$clientOptions = [
			'json' => $payload,
			'headers' => ['Content-Type' => 'application/json'],
		];

		if ($this->certPath !== null) {
			$clientOptions['cert'] = $this->certPath;
		}

		return $this->clientService->newClient()->post(uri: $uri, options: $clientOptions);

	}//end post()
}//end class
