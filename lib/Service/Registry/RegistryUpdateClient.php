<?php

/**
 * The one outbound call to OpenRegister's inbound update endpoint.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Registry
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Registry;

use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `POST /api/registry/{registry}/updates`. The change goes out, and nothing
 * about it is kept here.
 *
 * @spec openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md#requirement-a-polled-change-is-posted-to-openregister-not-stored-locally-req-rsc-003
 */
class RegistryUpdateClient {
	/**
	 * App id for app-config reads.
	 */
	public const APP_ID = 'integriq';

	/**
	 * App-config key holding the connector credential for the inbound endpoint.
	 */
	public const CREDENTIAL_KEY = 'registry_subscription.connector_credential';

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService HTTP client service.
	 * @param IURLGenerator $urlGenerator URL generator for the absolute endpoint.
	 * @param IAppConfig $appConfig App configuration store.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Post one change to OpenRegister.
	 *
	 * @param string $registryId Registry id, which names the endpoint.
	 * @param array<string,mixed> $payload The identity, the changed properties and the event reference.
	 *
	 * @return int The HTTP status, or 0 when the call could not be made.
	 */
	public function postUpdate(string $registryId, array $payload): int {
		$url = $this->urlGenerator->getAbsoluteURL('/index.php/apps/openregister/api/registry/' . rawurlencode($registryId) . '/updates');
		$credential = $this->appConfig->getValueString(self::APP_ID, self::CREDENTIAL_KEY, '');

		$headers = ['Content-Type' => 'application/json', 'OCS-APIRequest' => 'true'];
		if ($credential !== '') {
			$headers['Authorization'] = 'Basic ' . $credential;
		}

		try {
			$response = $this->clientService->newClient()->post(
				$url,
				[
					'body' => (json_encode($payload) ?: '{}'),
					'headers' => $headers,
					'timeout' => 15,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'registry-subscription.update.failed',
				[
					'registry' => $registryId,
					'error' => $e->getMessage(),
				]
			);

			return 0;
		}//end try

		return $response->getStatusCode();
	}//end postUpdate()
}//end class
