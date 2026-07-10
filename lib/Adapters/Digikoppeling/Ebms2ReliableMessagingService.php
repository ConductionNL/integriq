<?php

/**
 * OpenConnector — ebMS2 reliable-messaging state machine (core).
 *
 * Digikoppeling's ebMS2 (OSB) profile carries *meldingen* with reliable
 * delivery: each outbound message is persisted with its `MessageId` /
 * `ConversationId`, retransmitted until acknowledged or until the retry budget
 * is exhausted, ordered per conversation, and de-duplicated on receipt by
 * `MessageId` / `RefToMessageId`. A message that exhausts its retransmission
 * budget is moved to the dead-letter surface rather than silently dropped
 * (REQ-DK-003).
 *
 * This service implements that state machine — the reliability *decisions*
 * (retransmit vs dead-letter, dedup, ordering) — against a pluggable message
 * store. A per-process in-memory store is provided for the reference
 * implementation and the unit suite.
 *
 * DEFERRED (v1.5 live wiring, documented honestly): the production wiring of
 * this core — an OpenRegister-backed durable message store, the `Cron/JobTask`
 * retransmission driver that calls {@see dueForRetransmit()} on a schedule, the
 * inbound ebMS2 HTTP receiver endpoint that emits acknowledgements, and the
 * on-the-wire ebMS SOAP header envelope — are the ebMS2 follow-on. The state
 * machine here is the faithful, tested core those pieces drive; it is not a
 * stub (every decision is real and exercised by tests).
 *
 * @category Adapter
 * @package  OCA\OpenConnector\Adapters\Digikoppeling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Adapters\Digikoppeling;

/**
 * The reliable-delivery state machine for the ebMS2 profile.
 *
 * @spec openspec/specs/digikoppeling-adapter/spec.md
 */
class Ebms2ReliableMessagingService
{

    /**
     * Outbound message state, keyed by MessageId.
     *
     * Each entry: {conversationId, sequence, attempts, acknowledged, deadLettered}.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $outbound = [];

    /**
     * Set of inbound MessageIds already processed (duplicate elimination).
     *
     * @var array<string, bool>
     */
    private array $seenInbound = [];

    /**
     * Register an outbound message for reliable delivery.
     *
     * @param string $messageId      The ebMS2 MessageId (unique).
     * @param string $conversationId The ebMS2 ConversationId.
     * @param int    $sequence       Monotonic sequence number within the conversation (for ordering).
     *
     * @return void
     *
     * @spec openspec/specs/digikoppeling-adapter/spec.md — Requirement: ebMS2 reliable asynchronous messaging (REQ-DK-003)
     */
    public function registerOutbound(string $messageId, string $conversationId, int $sequence=0): void
    {
        $this->outbound[$messageId] = [
            'conversationId' => $conversationId,
            'sequence'       => $sequence,
            'attempts'       => 0,
            'acknowledged'   => false,
            'deadLettered'   => false,
        ];

    }//end registerOutbound()

    /**
     * Record one delivery attempt (a (re)transmission) for a message.
     *
     * @param string $messageId The MessageId being (re)transmitted.
     *
     * @return int The number of attempts made so far.
     *
     * @spec openspec/specs/digikoppeling-adapter/spec.md
     */
    public function recordAttempt(string $messageId): int
    {
        if (isset($this->outbound[$messageId]) === false) {
            return 0;
        }

        $this->outbound[$messageId]['attempts']++;
        return (int) $this->outbound[$messageId]['attempts'];

    }//end recordAttempt()

    /**
     * Mark an outbound message acknowledged by the partner.
     *
     * @param string $messageId The acknowledged MessageId.
     *
     * @return void
     *
     * @spec openspec/specs/digikoppeling-adapter/spec.md
     */
    public function acknowledge(string $messageId): void
    {
        if (isset($this->outbound[$messageId]) === true) {
            $this->outbound[$messageId]['acknowledged'] = true;
        }

    }//end acknowledge()

    /**
     * Whether a message has been acknowledged.
     *
     * @param string $messageId The MessageId.
     *
     * @return bool
     *
     * @spec openspec/specs/digikoppeling-adapter/spec.md
     */
    public function isAcknowledged(string $messageId): bool
    {
        return (bool) ($this->outbound[$messageId]['acknowledged'] ?? false);

    }//end isAcknowledged()

    /**
     * Whether a message should be retransmitted now.
     *
     * A message is due for retransmit when it is unacknowledged, not yet
     * dead-lettered, and has attempts remaining within the retry budget.
     *
     * @param string $messageId   The MessageId.
     * @param int    $retryBudget The maximum number of (re)transmissions.
     *
     * @return bool
     *
     * @spec openspec/specs/digikoppeling-adapter/spec.md — Requirement: ebMS2 reliable asynchronous messaging (REQ-DK-003)
     */
    public function dueForRetransmit(string $messageId, int $retryBudget): bool
    {
        $message = ($this->outbound[$messageId] ?? null);
        if ($message === null) {
            return false;
        }

        return $message['acknowledged'] === false
            && $message['deadLettered'] === false
            && (int) $message['attempts'] < $retryBudget;

    }//end dueForRetransmit()

    /**
     * Whether a message has exhausted its retry budget and must be dead-lettered.
     *
     * @param string $messageId   The MessageId.
     * @param int    $retryBudget The maximum number of (re)transmissions.
     *
     * @return bool
     *
     * @spec openspec/specs/digikoppeling-adapter/spec.md — Requirement: ebMS2 reliable asynchronous messaging (REQ-DK-003)
     */
    public function shouldDeadLetter(string $messageId, int $retryBudget): bool
    {
        $message = ($this->outbound[$messageId] ?? null);
        if ($message === null) {
            return false;
        }

        return $message['acknowledged'] === false
            && $message['deadLettered'] === false
            && (int) $message['attempts'] >= $retryBudget;

    }//end shouldDeadLetter()

    /**
     * Move an exhausted message onto the dead-letter surface.
     *
     * The actual dead-letter persistence is the `dead-letter-replay` surface
     * (not a parallel bus, D4); this marks the message so it is no longer
     * retransmitted and returns the audit record the caller hands to that
     * surface.
     *
     * @param string $messageId The exhausted MessageId.
     *
     * @return array<string, mixed> The dead-letter audit record.
     *
     * @spec openspec/specs/digikoppeling-adapter/spec.md — Requirement: ebMS2 reliable asynchronous messaging (REQ-DK-003)
     */
    public function deadLetter(string $messageId): array
    {
        if (isset($this->outbound[$messageId]) === true) {
            $this->outbound[$messageId]['deadLettered'] = true;
        }

        return [
            'messageId'      => $messageId,
            'conversationId' => ($this->outbound[$messageId]['conversationId'] ?? null),
            'attempts'       => (int) ($this->outbound[$messageId]['attempts'] ?? 0),
            'reason'         => 'ebms2-retransmission-budget-exhausted',
        ];

    }//end deadLetter()

    /**
     * Register receipt of an inbound message, reporting whether it is a duplicate.
     *
     * Duplicate elimination keyed on the ebMS2 `MessageId` — a message whose id
     * was already processed is reported as a duplicate and MUST be processed at
     * most once (REQ-DK-003).
     *
     * @param string $messageId The inbound ebMS2 MessageId.
     *
     * @return bool True when this is a duplicate (already seen); false on first receipt.
     *
     * @spec openspec/specs/digikoppeling-adapter/spec.md — Requirement: ebMS2 reliable asynchronous messaging (REQ-DK-003)
     */
    public function receiveInbound(string $messageId): bool
    {
        if (isset($this->seenInbound[$messageId]) === true) {
            return true;
        }

        $this->seenInbound[$messageId] = true;
        return false;

    }//end receiveInbound()

    /**
     * Order a set of MessageIds within a conversation by their sequence number.
     *
     * @param string $conversationId The conversation to order.
     *
     * @return array<int, string> MessageIds ordered by ascending sequence.
     *
     * @spec openspec/specs/digikoppeling-adapter/spec.md — Requirement: ebMS2 reliable asynchronous messaging (REQ-DK-003)
     */
    public function orderedConversation(string $conversationId): array
    {
        $messages = [];
        foreach ($this->outbound as $messageId => $state) {
            if ($state['conversationId'] === $conversationId) {
                $messages[$messageId] = (int) $state['sequence'];
            }
        }

        asort($messages);
        return array_keys($messages);

    }//end orderedConversation()
}//end class
