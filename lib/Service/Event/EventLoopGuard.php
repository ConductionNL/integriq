<?php

/**
 * Integriq CloudEvent loop guard.
 *
 * Decides whether an object mutation may be forwarded into the CloudEvent
 * machinery, or whether forwarding it would feed the machinery its own
 * output.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

namespace OCA\Integriq\Service\Event;

/**
 * Pure decision for "may this object mutation become a CloudEvent?".
 *
 * Three answers, tried in this order, and the order is the point.
 *
 * 1. **The marker.** A CloudEvent this app wrote carries a generated-by
 *    marker in its payload. That marker travels with the row, so it keeps
 *    working after a register is renamed, after the schema is copied into a
 *    second register, and on an instance where the ids resolve to nothing.
 * 2. **The schema id.** The machinery's own schemas, resolved to ids, are
 *    never forwarded. This catches rows written before the marker existed.
 * 3. **The ceiling.** If the first two say forward, a chain that has already
 *    produced {@see self::MAX_CHAIN} events in one request stops anyway.
 *
 * The ceiling exists because the other two guards can both go quiet. The id
 * set on this instance is resolved by reading one existing row per schema,
 * so on an instance whose event table is empty (a fresh install, or the
 * moment after the remediation purge) it resolves to nothing at all, and the
 * process caches that empty answer. Without a ceiling the answer to "what
 * does a loop do before it is stopped" is: everything it can. Measured on
 * the dev instance on 2026-07-28, that was one object create producing 255
 * CloudEvents, a table holding 45,715 rows of which 45,398 were generated
 * from other rows, growing at 3,000 to 5,000 an hour, with object creates
 * taking over 120 seconds and often never returning.
 *
 * With the ceiling the answer is bounded and named: at most MAX_CHAIN events
 * from one request, then a refusal that says which guard stopped it.
 *
 * This class performs no IO. It is handed the object payload, the schema id
 * and the resolved self-schema ids, and it returns a decision.
 *
 * @spec openspec/changes/stop-cloudevent-recursion/tasks.md#task-1
 */
class EventLoopGuard {
	/**
	 * Payload key carrying the marker this app stamps on its own CloudEvents.
	 *
	 * @var string
	 */
	public const MARKER_KEY = 'x-generated-by';

	/**
	 * Value of {@see self::MARKER_KEY} on a CloudEvent this app wrote.
	 *
	 * @var string
	 */
	public const MARKER_VALUE = 'integriq';

	/**
	 * Most CloudEvents one request may produce before forwarding stops.
	 *
	 * Chosen above any legitimate fan-out (one object mutation yields one
	 * CloudEvent) and far below the cost of a storm.
	 *
	 * @var int
	 */
	public const MAX_CHAIN = 25;

	/**
	 * Forward the mutation: none of the guards objected.
	 *
	 * @var string
	 */
	public const FORWARD = 'forward';

	/**
	 * Refused: the object carries this app's own generated-by marker.
	 *
	 * @var string
	 */
	public const REFUSED_MARKER = 'carries-the-generated-by-marker';

	/**
	 * Refused: the object belongs to one of the machinery's own schemas.
	 *
	 * @var string
	 */
	public const REFUSED_SELF_SCHEMA = 'belongs-to-an-event-machinery-schema';

	/**
	 * Refused: this request has already produced MAX_CHAIN CloudEvents.
	 *
	 * @var string
	 */
	public const REFUSED_CEILING = 'chain-ceiling-reached-in-one-request';

	/**
	 * CloudEvents forwarded so far in this request.
	 *
	 * @var int
	 */
	private int $chain = 0;

	/**
	 * Decide whether one object mutation may be forwarded.
	 *
	 * @param array<string, mixed> $payload       The object's stored payload.
	 * @param string               $schemaId      The object's schema id, as a string; empty when unknown.
	 * @param array<int, string>   $selfSchemaIds Schema ids belonging to the event machinery.
	 *
	 * @return array{forward: bool, reason: string, chain: int, ceiling: int, identified: bool}
	 */
	public function decide(array $payload, string $schemaId, array $selfSchemaIds): array {
		$identified = ($selfSchemaIds !== []);

		if ($this->carriesMarker(payload: $payload) === true) {
			return $this->refuse(reason: self::REFUSED_MARKER, identified: $identified);
		}

		if ($schemaId !== '' && in_array($schemaId, $selfSchemaIds, true) === true) {
			return $this->refuse(reason: self::REFUSED_SELF_SCHEMA, identified: $identified);
		}

		if ($this->chain >= self::MAX_CHAIN) {
			return $this->refuse(reason: self::REFUSED_CEILING, identified: $identified);
		}

		$this->chain++;

		return [
			'forward' => true,
			'reason' => self::FORWARD,
			'chain' => $this->chain,
			'ceiling' => self::MAX_CHAIN,
			'identified' => $identified,
		];

	}//end decide()

	/**
	 * Whether a payload carries this app's generated-by marker.
	 *
	 * The marker is compared case-insensitively on its value so a payload
	 * that came back through a store which upper-cased it still matches; the
	 * key itself is read as written, because a CloudEvent extension key is
	 * lower-case by the specification.
	 *
	 * @param array<string, mixed> $payload The object's stored payload.
	 *
	 * @return boolean
	 */
	public function carriesMarker(array $payload): bool {
		$value = ($payload[self::MARKER_KEY] ?? null);
		if (is_string($value) === false) {
			return false;
		}

		return strtolower(trim($value)) === self::MARKER_VALUE;

	}//end carriesMarker()

	/**
	 * Stamp a CloudEvent payload so this guard recognises it later.
	 *
	 * @param array<string, mixed> $payload The CloudEvent about to be written.
	 *
	 * @return array<string, mixed> The payload with the marker applied.
	 */
	public function stamp(array $payload): array {
		$payload[self::MARKER_KEY] = self::MARKER_VALUE;

		return $payload;

	}//end stamp()

	/**
	 * How many CloudEvents this request has forwarded so far.
	 *
	 * @return integer
	 */
	public function chain(): int {
		return $this->chain;

	}//end chain()

	/**
	 * Build a refusal decision.
	 *
	 * A refusal is named. It is never expressed as "nothing happened",
	 * because a guard that went inert and a guard that stopped a loop both
	 * forward zero events, and those two must stay distinguishable in a log.
	 *
	 * @param string  $reason     One of the REFUSED_* constants.
	 * @param boolean $identified Whether the self-schema ids resolved to anything.
	 *
	 * @return array{forward: bool, reason: string, chain: int, ceiling: int, identified: bool}
	 */
	private function refuse(string $reason, bool $identified): array {
		return [
			'forward' => false,
			'reason' => $reason,
			'chain' => $this->chain,
			'ceiling' => self::MAX_CHAIN,
			'identified' => $identified,
		];

	}//end refuse()
}//end class
