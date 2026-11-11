<?php

/**
 * Integriq brokered JSON dispatch.
 *
 * Sends one call through the OpenRegister credential broker the way every
 * REST provider does: the source's authentication plus an optional JSON body
 * as the call configuration, refuse closed when it names no credentialRef,
 * prepare, dispatch, and hand every failure to the provider's refusals so the
 * provider answers in its own words. Reading the answer stays with the
 * provider.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Helper
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
 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-the-rest-provider-brokers-its-token
 * @spec openspec/specs/peppol-access-point-connector/spec.md#scenario-the-rest-provider-brokers-its-api-key
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Helper;

use Exception;
use OCA\Integriq\Service\BrokeredCallConfigurationException;
use OCA\Integriq\Service\BrokeredCallService;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Dispatches a brokered call for one REST provider.
 *
 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-the-rest-provider-brokers-its-token
 * @spec openspec/specs/peppol-access-point-connector/spec.md#scenario-the-rest-provider-brokers-its-api-key
 */
final class BrokeredJsonDispatch {

	/**
	 * Constructor.
	 *
	 * @param BrokeredCallService  $broker     The credential broker.
	 * @param BrokeredCallRefusals $refusals   Builds the provider's refusals.
	 * @param string               $sourceType The source type the broker prepares the call for.
	 */
	public function __construct(
		private readonly BrokeredCallService $broker,
		private readonly BrokeredCallRefusals $refusals,
		private readonly string $sourceType,
	) {

	}//end __construct()

	/**
	 * Send the call and return the provider's answer.
	 *
	 * @param array        $sourceConfiguration The source's `configuration` object.
	 * @param BrokeredCall $call                The method, URL and optional JSON body.
	 *
	 * @return ResponseInterface The answer.
	 *
	 * @throws Exception The provider's refusal when no credential is configured or the call fails.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-the-rest-provider-brokers-its-token
	 */
	public function send(array $sourceConfiguration, BrokeredCall $call): ResponseInterface {
		$config = ['authentication' => ($sourceConfiguration['authentication'] ?? [])];
		if ($call->json !== null) {
			$config['json'] = $call->json;
		}

		if ($this->broker->hasCredentialRef(config: $config) === false) {
			throw $this->refusals->credentialMissing();
		}

		return $this->dispatch(config: $config, call: $call);
	}//end send()

	/**
	 * Prepare and dispatch the call through the broker.
	 *
	 * @param array        $config The brokered call configuration.
	 * @param BrokeredCall $call   The method and URL.
	 *
	 * @return ResponseInterface The answer.
	 *
	 * @throws Exception The provider's refusal when the broker refuses or the transport fails.
	 */
	private function dispatch(array $config, BrokeredCall $call): ResponseInterface {
		try {
			$dispatch = $this->prepare(config: $config);

			return $this->broker->dispatch(
				credentialId: $dispatch['credentialId'],
				actingUserId: $dispatch['actingUserId'],
				method: $call->method,
				url: $call->url,
				config: $config
			);
		} catch (BrokeredCallConfigurationException $exception) {
			throw $this->refusals->configurationRefused(cause: $exception);
		} catch (Throwable $exception) {
			throw $this->refusals->transportFailed(cause: $exception);
		}
	}//end dispatch()

	/**
	 * Ask the broker which credential and acting user the call runs under.
	 *
	 * @param array $config The brokered call configuration.
	 *
	 * @return array{credentialId: string, actingUserId: string|null} The prepared dispatch.
	 *
	 * @throws BrokeredCallConfigurationException When the broker refuses the configuration.
	 */
	private function prepare(array $config): array {
		return $this->broker->prepare(
			config: $config,
			sourceData: ['type' => $this->sourceType],
			asynchronous: false
		);
	}//end prepare()
}//end class
