<?php

/**
 * A stand-in for Hermiq's verify endpoint that answers exactly the contract.
 *
 * The contract is ~/memcap-work/build-all/for-ruben/hermiq-approval-verification-contract.md
 * (DECISIONS row 31): a signed verdict that echoes the request. This fake holds
 * approvals the way Hermiq would and signs with a real Ed25519 key, so the
 * verifier is tested against real signatures, not a mocked yes.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Mcp;

use OCA\Integriq\Service\AgentTools\ApprovalVerdictVerifier;
use OCA\Integriq\Service\AgentTools\HermiqVerdictClient;

class FakeHermiq implements HermiqVerdictClient {

	/** @var string Ed25519 key pair. */
	private string $keyPair;

	/** @var array<string,array<string,mixed>> Approvals by id: toolId, binding, agent, status, decidedBy. */
	public array $approvals = [];

	/** @var int Seconds added to issuedAt, to make a verdict stale. */
	public int $clockSkew = 0;

	/** @var callable|null Rewrites a verdict after signing, to forge one. */
	public $tamper = null;

	/** @var array<int,array<string,string>> Every request received. */
	public array $requests = [];

	public function __construct() {
		$this->keyPair = sodium_crypto_sign_keypair();
	}

	public function publicKey(): string {
		return base64_encode(sodium_crypto_sign_publickey($this->keyPair));
	}

	public function requestVerdict(array $request): array {
		$this->requests[] = $request;
		$approval = ($this->approvals[$request['approvalId']] ?? null);
		$reason   = 'approved';
		if ($approval === null) {
			$reason = 'unknown';
		} else if ($approval['toolId'] !== $request['toolId'] || $approval['binding'] !== $request['binding']) {
			$reason = 'binding-mismatch';
		} else if ($approval['status'] !== 'approved') {
			$reason = $approval['status'];
		} else if ($approval['decidedBy'] === $request['actingAgent'] || $approval['agent'] !== $request['actingAgent']) {
			$reason = 'approver-is-agent';
		}

		$verdict = $request + [
			'approved'  => ($reason === 'approved'),
			'reason'    => $reason,
			'decidedBy' => ($approval['decidedBy'] ?? null),
			'decidedAt' => '2026-09-30T10:00:00+00:00',
			'expiresAt' => null,
			'issuedAt'  => gmdate(DATE_ATOM, (time() + $this->clockSkew)),
		];
		$signature = sodium_crypto_sign_detached(ApprovalVerdictVerifier::canonical(verdict: $verdict), sodium_crypto_sign_secretkey($this->keyPair));
		if ($this->tamper !== null) {
			$verdict = ($this->tamper)($verdict);
		}

		return ['verdict' => $verdict, 'signature' => base64_encode($signature)];
	}
}
