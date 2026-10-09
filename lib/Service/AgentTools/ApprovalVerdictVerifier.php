<?php

/**
 * Trusts an approval only on Hermiq's signed verdict.
 *
 * DECISIONS row 31 (30 Sep 2026): integriq never reads Hermiq's approval
 * objects and never recomputes Hermiq's correlation hash. It asks Hermiq's
 * verify endpoint and believes only an answer that is signed with Hermiq's
 * published key, echoes exactly what was asked, is fresh, says approved, and
 * names a human approver who is not the acting agent. Anything else, including
 * Hermiq being absent, is a refusal.
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

use OCP\IAppConfig;

/**
 * Verifies one approval against one staged batch.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
 */
class ApprovalVerdictVerifier {

	/**
	 * The Hermiq app value that holds the verdict public key (base64 Ed25519).
	 */
	public const PUBLIC_KEY_APP = 'hermiq';

	/**
	 * The app value key of that public key.
	 */
	public const PUBLIC_KEY_NAME = 'approval_verdict_public_key';

	/**
	 * How old a verdict may be, in seconds, when it arrives.
	 */
	public const MAX_AGE_SECONDS = 120;

	/**
	 * The fields Hermiq must echo unchanged.
	 */
	private const ECHOED = ['approvalId', 'toolId', 'binding', 'actingAgent', 'nonce'];

	/**
	 * Build the verifier.
	 *
	 * @param HermiqVerdictClient $client    The transport to Hermiq.
	 * @param IAppConfig          $appConfig Where Hermiq publishes its key.
	 */
	public function __construct(
		private readonly HermiqVerdictClient $client,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Ask Hermiq about one approval and return the approver when it holds.
	 *
	 * @param string $approvalId  The approval the agent presents.
	 * @param string $toolId      The tool the batch is for.
	 * @param string $binding     The staged batch's binding hash.
	 * @param string $actingAgent The agent that calls the tool.
	 *
	 * @return string The uid of the human who approved.
	 *
	 * @throws AgentActionRefusedException When the verdict does not hold.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	public function verify(string $approvalId, string $toolId, string $binding, string $actingAgent): string {
		if ($approvalId === '' || $actingAgent === '') {
			throw new AgentActionRefusedException(reason: 'no-token');
		}

		$request = [
			'approvalId'  => $approvalId,
			'toolId'      => $toolId,
			'binding'     => $binding,
			'actingAgent' => $actingAgent,
			'nonce'       => bin2hex(random_bytes(16)),
		];

		$verdict = $this->signedVerdict(answer: $this->client->requestVerdict(request: $request), publicKey: $this->publicKey());
		$this->assertAnswers(verdict: $verdict, request: $request);

		return $this->approver(verdict: $verdict, actingAgent: $actingAgent);
	}//end verify()

	/**
	 * Hermiq's published verdict key.
	 *
	 * @return string The raw Ed25519 public key.
	 *
	 * @throws AgentActionRefusedException When none is published.
	 */
	private function publicKey(): string {
		$publicKey = base64_decode($this->appConfig->getValueString(app: self::PUBLIC_KEY_APP, key: self::PUBLIC_KEY_NAME), true);
		if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
			throw new AgentActionRefusedException(reason: 'no-verifier-key');
		}

		return $publicKey;
	}//end publicKey()

	/**
	 * The verdict, when its signature verifies against the key.
	 *
	 * @param array<string,mixed> $answer    Hermiq's decoded answer.
	 * @param string              $publicKey The raw public key.
	 *
	 * @return array<string,mixed> The verdict.
	 *
	 * @throws AgentActionRefusedException When it is unsigned or the signature fails.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	private function signedVerdict(array $answer, string $publicKey): array {
		$verdict   = ($answer['verdict'] ?? null);
		$signature = base64_decode((string)($answer['signature'] ?? ''), true);
		if (is_array($verdict) === false || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
			throw new AgentActionRefusedException(reason: 'unsigned-verdict');
		}

		if (sodium_crypto_sign_verify_detached($signature, self::canonical(verdict: $verdict), $publicKey) === false) {
			throw new AgentActionRefusedException(reason: 'bad-signature');
		}

		return $verdict;
	}//end signedVerdict()

	/**
	 * Refuse a verdict for another request, or an old one.
	 *
	 * @param array<string,mixed>  $verdict The signed verdict.
	 * @param array<string,string> $request What was asked.
	 *
	 * @return void
	 *
	 * @throws AgentActionRefusedException When it does not answer this request now.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	private function assertAnswers(array $verdict, array $request): void {
		foreach (self::ECHOED as $field) {
			if (($verdict[$field] ?? null) !== $request[$field]) {
				throw new AgentActionRefusedException(reason: 'verdict-for-another-request');
			}
		}

		$issuedAt = strtotime((string)($verdict['issuedAt'] ?? ''));
		if ($issuedAt === false || abs(time() - $issuedAt) > self::MAX_AGE_SECONDS) {
			throw new AgentActionRefusedException(reason: 'stale-verdict');
		}
	}//end assertAnswers()

	/**
	 * The human approver, when the verdict says approved by someone other than the agent.
	 *
	 * @param array<string,mixed> $verdict     The signed verdict.
	 * @param string              $actingAgent The agent.
	 *
	 * @return string The approver's uid.
	 *
	 * @throws AgentActionRefusedException When it is not approved, or approved by the agent.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	private function approver(array $verdict, string $actingAgent): string {
		if (($verdict['approved'] ?? false) !== true) {
			throw new AgentActionRefusedException(reason: 'not-approved:' . (string)($verdict['reason'] ?? 'unknown'));
		}

		$decidedBy = (string)($verdict['decidedBy'] ?? '');
		if ($decidedBy === '' || $decidedBy === $actingAgent) {
			throw new AgentActionRefusedException(reason: 'approver-is-agent');
		}

		return $decidedBy;
	}//end approver()

	/**
	 * The bytes Hermiq signs: the verdict with its keys sorted, as JSON.
	 *
	 * @param array<string,mixed> $verdict The verdict object.
	 *
	 * @return string The canonical JSON.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
	 */
	public static function canonical(array $verdict): string {
		ksort($verdict);
		return (string)json_encode($verdict, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}//end canonical()
}//end class
