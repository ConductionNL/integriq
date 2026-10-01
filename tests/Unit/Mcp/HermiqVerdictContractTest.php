<?php

/**
 * Integriq's approval verifier against Hermiq's real verdict producer.
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
 * @spec openspec/changes/hermiq-ai-tooling/specs/openconnector-mcp-tool-surface/spec.md#requirement-req-mcp-107--run-replay-and-discard-must-be-two-phase-with-a-server-verified-human-approval-bound-to-the-batch
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Mcp;

require_once __DIR__ . '/../../stubs/Hermiq/ApprovalService.php';
require_once __DIR__ . '/../../stubs/Hermiq/ApprovalVerdictSigner.php';
require_once __DIR__ . '/../../stubs/Hermiq/ApprovalVerdictService.php';

use OCA\Hermiq\Service\Approval\ApprovalVerdictService;
use OCA\Hermiq\Service\Approval\ApprovalVerdictSigner;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Integriq\Service\AgentTools\AgentActionRefusedException;
use OCA\Integriq\Service\AgentTools\ApprovalVerdictVerifier;
use OCA\Integriq\Service\AgentTools\HermiqVerdictClient;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * hermiq-ai-tooling Task 6, the contract half (DECISIONS rows 31 and 40,
 * hermiq#1045, built in hermiq PR #1048).
 *
 * FakeHermiq tests the verifier against a fake that answers the contract as
 * integriq drafted it. This test runs Hermiq's REAL ApprovalVerdictService and
 * ApprovalVerdictSigner (copied unchanged into tests/stubs/Hermiq) behind the
 * transport, sends the answer through JSON as the HTTP client does, and lets
 * integriq read the public key from the same app config Hermiq writes it to.
 * A drift in field names, canonical JSON, key publication or time format on
 * either side fails here.
 */
class HermiqVerdictContractTest extends TestCase {

	private const AGENT = 'agent-7f3c';
	private const TOOL = 'integriq.replayDeadLetters';
	private const BINDING = 'b5bb9d8014a0f9b1d61e21e796d78dccdf1352f23cd32812f4850b878ae4944c';

	/** @var array<string, string> App config values, shared by both apps. */
	private array $config = [];

	/** @var array<string, mixed>|null The approval Hermiq holds. */
	private ?array $approval = null;

	/**
	 * An app config both apps read and write, backed by $this->config.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default='') => ($this->config[$app . '/' . $key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) {
				$this->config[$app . '/' . $key] = $value;
				return true;
			}
		);

		return $appConfig;
	}//end appConfig()

	/**
	 * Integriq's verifier, with Hermiq's real verdict service behind the transport.
	 *
	 * @return ApprovalVerdictVerifier
	 */
	private function verifier(): ApprovalVerdictVerifier {
		$appConfig = $this->appConfig();

		$approvals = $this->createMock(ApprovalService::class);
		$approvals->method('loadApproval')->willReturnCallback(
			function (string $uuid) {
				if ($this->approval === null || $uuid !== 'approval-1') {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setObject($this->approval);
				return $entity;
			}
		);

		$agent = new ObjectEntity();
		$agent->setObject(['actingUser' => 'agent-owner']);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($agent);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn () => time());

		$hermiq = new ApprovalVerdictService($approvals, $objects, new ApprovalVerdictSigner($appConfig), $clock);

		$transport = new class ($hermiq) implements HermiqVerdictClient {
			/**
			 * @param ApprovalVerdictService $hermiq Hermiq's real verdict service.
			 */
			public function __construct(private ApprovalVerdictService $hermiq) {
			}

			/**
			 * Hermiq's controller answer, through JSON as the wire carries it.
			 *
			 * @param array<string,string> $request The verify request.
			 *
			 * @return array<string,mixed>
			 */
			public function requestVerdict(array $request): array {
				return json_decode((string)json_encode($this->hermiq->verify(request: $request)), true);
			}
		};

		// Hermiq makes its key pair on first use; before that nothing is published.
		(new ApprovalVerdictSigner($appConfig))->sign(verdict: []);

		return new ApprovalVerdictVerifier($transport, $appConfig);
	}//end verifier()

	/**
	 * A staged-batch toolcall approval as Hermiq stores it (design D4).
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private function approval(array $overrides=[]): array {
		return array_merge(
			[
				'sourceType' => 'toolcall',
				'toolId'     => self::TOOL,
				'binding'    => self::BINDING,
				'agentId'    => self::AGENT,
				'status'     => 'approved',
				'decidedBy'  => 'beheerder',
				'decidedAt'  => gmdate('c', time() - 60),
			],
			$overrides
		);
	}//end approval()

	/**
	 * Ask integriq's verifier, returning the approver or the refusal reason.
	 *
	 * @return string
	 */
	private function ask(): string {
		try {
			return 'approved-by:' . $this->verifier()->verify(
				approvalId: 'approval-1',
				toolId: self::TOOL,
				binding: self::BINDING,
				actingAgent: self::AGENT
			);
		} catch (AgentActionRefusedException $e) {
			return $e->reason;
		}
	}//end ask()

	/**
	 * An approved batch: Hermiq's signed verdict verifies and names the approver.
	 *
	 * @return void
	 */
	public function testAnApprovedBatchVerifiesAndNamesTheApprover(): void {
		$this->approval = $this->approval();
		$this->assertSame('approved-by:beheerder', $this->ask());
		$this->assertSame(
			SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
			strlen((string)base64_decode($this->config['hermiq/' . ApprovalVerdictVerifier::PUBLIC_KEY_NAME], true)),
			'Integriq reads the key where Hermiq publishes it.'
		);
	}//end testAnApprovedBatchVerifiesAndNamesTheApprover()

	/**
	 * Every refusal Hermiq signs reaches integriq as a refusal with Hermiq's reason.
	 *
	 * @return void
	 */
	public function testHermiqsRefusalsArriveWithTheirReason(): void {
		$cases = [
			'not-approved:unknown'          => null,
			'not-approved:binding-mismatch' => ['binding' => str_repeat('0', 64)],
			'not-approved:pending'          => ['status' => 'pending', 'decidedBy' => null, 'decidedAt' => null],
			'not-approved:rejected'         => ['status' => 'denied'],
			'not-approved:expired'          => ['decidedAt' => gmdate('c', time() - ApprovalService::TOOLCALL_APPROVAL_TTL_SECONDS - 60)],
			'not-approved:approver-is-agent' => ['decidedBy' => 'agent-owner'],
		];
		foreach ($cases as $reason => $overrides) {
			$this->config   = [];
			$this->approval = ($overrides === null ? null : $this->approval($overrides));
			$this->assertSame($reason, $this->ask(), $reason);
		}
	}//end testHermiqsRefusalsArriveWithTheirReason()

	/**
	 * A verdict signed with a key other than the published one is refused.
	 *
	 * @return void
	 */
	public function testAVerdictUnderAnotherKeyIsRefused(): void {
		$this->approval = $this->approval();
		$verifier = $this->verifier();
		$this->config['hermiq/' . ApprovalVerdictVerifier::PUBLIC_KEY_NAME] = base64_encode(
			sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())
		);

		try {
			$verifier->verify(approvalId: 'approval-1', toolId: self::TOOL, binding: self::BINDING, actingAgent: self::AGENT);
			$this->fail('A verdict under another key must be refused.');
		} catch (AgentActionRefusedException $e) {
			$this->assertSame('bad-signature', $e->reason);
		}
	}//end testAVerdictUnderAnotherKeyIsRefused()
}//end class
