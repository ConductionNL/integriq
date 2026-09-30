<?php

/**
 * Asks Hermiq's verify endpoint on this instance for a verdict.
 *
 * @category Service
 * @package  OCA\Integriq\Service\AgentTools
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\AgentTools;

use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use Throwable;

/**
 * The HTTP transport of the verification contract (design D7). Without Hermiq
 * enabled there is nobody to ask, so every approval is refused.
 */
class HttpHermiqVerdictClient implements HermiqVerdictClient {

	/**
	 * Hermiq's verify endpoint, relative to the instance.
	 */
	public const VERIFY_PATH = '/index.php/apps/hermiq/api/approvals/verify';

	/**
	 * Build the client.
	 *
	 * @param IClientService $clientService The HTTP client factory.
	 * @param IURLGenerator  $urlGenerator  Builds the absolute verify URL.
	 * @param IAppManager    $appManager    Tells whether Hermiq is there.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * Send one verify request and return Hermiq's decoded answer.
	 *
	 * @param array<string,string> $request approvalId, toolId, binding, actingAgent, nonce.
	 *
	 * @return array<string,mixed> The decoded answer.
	 *
	 * @throws AgentActionRefusedException When Hermiq is absent or does not answer.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	public function requestVerdict(array $request): array {
		if ($this->appManager->isInstalled('hermiq') === false) {
			throw new AgentActionRefusedException(reason: 'hermiq-unavailable');
		}

		try {
			$response = $this->clientService->newClient()->post(
				$this->urlGenerator->getAbsoluteURL(self::VERIFY_PATH),
				[
					'json'        => $request,
					'timeout'     => 10,
					'http_errors' => false,
				]
			);
			$answer   = json_decode((string)$response->getBody(), true);
		} catch (Throwable $e) {
			throw new AgentActionRefusedException(reason: 'hermiq-unreachable');
		}

		if (is_array($answer) === false) {
			throw new AgentActionRefusedException(reason: 'hermiq-unreadable');
		}

		return $answer;
	}//end requestVerdict()
}//end class
