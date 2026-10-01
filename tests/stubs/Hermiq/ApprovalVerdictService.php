<?php

/**
 * Hermiq ApprovalVerdictService.
 *
 * TEST COPY: ConductionNL/hermiq development on 1 Oct 2026 (after PR #1048), unchanged but for
 * its spec tags, so integriq's verifier is tested against the real producer.
 *
 * Answers another app's question "did a person approve this exact staged batch for
 * this agent?" with a signed verdict (hermiq#1045). The verdict echoes the request,
 * so it cannot be replayed for another batch or request, and names the first check
 * that failed.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Approval
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Approval;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Hermiq\Service\ApprovalService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

/**
 * Builds and signs the verdict on a staged-batch toolcall approval.
 *
 */
class ApprovalVerdictService
{

    /**
     * The request fields, every one a non-empty string, echoed in the verdict.
     *
     * @var array<int, string>
     */
    public const REQUEST_FIELDS = ['approvalId', 'toolId', 'binding', 'actingAgent', 'nonce'];

    /**
     * Constructor.
     *
     * @param ApprovalService       $approvals     Loads the approval.
     * @param ObjectService         $objectService Loads the acting agent (its principal).
     * @param ApprovalVerdictSigner $signer        Signs the verdict.
     * @param ITimeFactory          $timeFactory   The clock (issuedAt, expiry).
     *
     */
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ObjectService $objectService,
        private readonly ApprovalVerdictSigner $signer,
        private readonly ITimeFactory $timeFactory,
    ) {
    }//end __construct()

    /**
     * Answer a verify request with {verdict, signature}.
     *
     * @param array<string, mixed> $request The five request fields.
     *
     * @return array{verdict: array<string, mixed>, signature: string}
     *
     * @throws InvalidArgumentException When a field is missing, empty or not a string.
     *
     */
    public function verify(array $request): array
    {
        $verdict = [];
        foreach (self::REQUEST_FIELDS as $field) {
            $value = ($request[$field] ?? null);
            if (is_string($value) === false || $value === '') {
                throw new InvalidArgumentException($field.' is missing or not a string.');
            }

            $verdict[$field] = $value;
        }

        $now      = $this->timeFactory->getTime();
        $approval = $this->approvals->loadApproval(uuid: $verdict['approvalId']);
        $data     = [];
        if ($approval !== null) {
            $data = $approval->getObject();
        }

        $decidedAt = $this->instant(value: ($data['decidedAt'] ?? null));
        $expiresAt = null;
        if ($decidedAt !== null) {
            $expiresAt = $decidedAt->modify('+'.ApprovalService::TOOLCALL_APPROVAL_TTL_SECONDS.' seconds');
        }

        $reason = $this->reason(approval: $approval, data: $data, request: $verdict, expiresAt: $expiresAt, now: $now);

        $decidedBy = null;
        if (is_string($data['decidedBy'] ?? null) === true && $data['decidedBy'] !== '') {
            $decidedBy = $data['decidedBy'];
        }

        $verdict['approved']  = ($reason === 'approved');
        $verdict['reason']    = $reason;
        $verdict['decidedBy'] = $decidedBy;
        $verdict['decidedAt'] = $decidedAt?->format('c');
        $verdict['expiresAt'] = $expiresAt?->format('c');
        $verdict['issuedAt']  = (new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('UTC'))->format('c');

        return [
            'verdict'   => $verdict,
            'signature' => $this->signer->sign(verdict: $verdict),
        ];

    }//end verify()

    /**
     * The first check that fails, in the contract's order, or 'approved'.
     *
     * @param ObjectEntity|null      $approval  The approval, or null when absent.
     * @param array<string, mixed>   $data      The approval's fields.
     * @param array<string, string>  $request   The validated request fields.
     * @param DateTimeImmutable|null $expiresAt When the approval stops holding.
     * @param int                    $now       The current unix time.
     *
     * @return string
     *
     */
    private function reason(?ObjectEntity $approval, array $data, array $request, ?DateTimeImmutable $expiresAt, int $now): string
    {
        $refusal = $this->identityRefusal(approval: $approval, data: $data, request: $request);
        $refusal ??= $this->decisionRefusal(data: $data, expiresAt: $expiresAt, now: $now);
        if ($refusal !== null) {
            return $refusal;
        }

        $decidedBy = (string) ($data['decidedBy'] ?? '');
        if ($decidedBy === '' || in_array($decidedBy, $this->agentIdentities(agentId: $request['actingAgent']), true) === true) {
            return 'approver-is-agent';
        }

        return 'approved';

    }//end reason()

    /**
     * 'unknown' when there is no staged-batch toolcall approval, 'binding-mismatch'
     * when it is for another tool, batch or agent, else null.
     *
     * @param ObjectEntity|null     $approval The approval, or null when absent.
     * @param array<string, mixed>  $data     The approval's fields.
     * @param array<string, string> $request  The validated request fields.
     *
     * @return string|null
     *
     */
    private function identityRefusal(?ObjectEntity $approval, array $data, array $request): ?string
    {
        $binding = ($data['binding'] ?? null);
        if ($approval === null || ($data['sourceType'] ?? null) !== 'toolcall' || is_string($binding) === false || $binding === '') {
            return 'unknown';
        }

        if (($data['toolId'] ?? null) !== $request['toolId']
            || hash_equals($binding, $request['binding']) === false
            || ($data['agentId'] ?? null) !== $request['actingAgent']
        ) {
            return 'binding-mismatch';
        }

        return null;

    }//end identityRefusal()

    /**
     * 'pending', 'rejected' or 'expired' when the decision does not hold now, else null.
     *
     * @param array<string, mixed>   $data      The approval's fields.
     * @param DateTimeImmutable|null $expiresAt When the approval stops holding.
     * @param int                    $now       The current unix time.
     *
     * @return string|null
     *
     */
    private function decisionRefusal(array $data, ?DateTimeImmutable $expiresAt, int $now): ?string
    {
        $status = (string) ($data['status'] ?? '');
        if ($status === 'pending') {
            return 'pending';
        }

        if ($status !== 'approved') {
            return 'rejected';
        }

        if ($expiresAt === null || $expiresAt->getTimestamp() < $now) {
            return 'expired';
        }

        return null;

    }//end decisionRefusal()

    /**
     * The identities an agent acts as: its id and its principal (actingUser, or the
     * legacy user). An agent that cannot be read yields only its id, and an approval
     * the agent itself decided is still refused by that.
     *
     * @param string $agentId The acting agent.
     *
     * @return array<int, string>
     *
     */
    private function agentIdentities(string $agentId): array
    {
        $identities = [$agentId];
        try {
            $agent = $this->objectService->find(
                id: $agentId,
                register: 'hermiq',
                schema: 'agent',
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable) {
            return $identities;
        }

        if (($agent instanceof ObjectEntity) === false) {
            return $identities;
        }

        $data = $agent->getObject();
        foreach (['actingUser', 'user'] as $field) {
            if (is_string($data[$field] ?? null) === true && $data[$field] !== '') {
                $identities[] = $data[$field];
            }
        }

        return $identities;

    }//end agentIdentities()

    /**
     * Parse a stored ISO 8601 instant into UTC, or null.
     *
     * @param mixed $value The stored value.
     *
     * @return DateTimeImmutable|null
     *
     */
    private function instant(mixed $value): ?DateTimeImmutable
    {
        if (is_string($value) === false || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }

    }//end instant()
}//end class
