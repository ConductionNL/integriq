<?php

/**
 * CallEventDeduplicator — has this exact call event already been handled?
 *
 * A PBX retries. It retries on a timeout it caused itself, on a 500, and often
 * on a 200 it did not read in time. Without this, one ringing event becomes
 * three CallEvents, and the agent's panel pops the same caller three times.
 *
 * WHAT THIS GUARANTEES, AND WHAT IT DOES NOT.
 *
 * It is a distributed cache, not a ledger. Two requests arriving in the same
 * millisecond on two nodes can both pass, and an entry evicted under memory
 * pressure lets a much later retry through. Both are rare and both are
 * survivable: a duplicated `ringing` pops a panel twice, which is a nuisance.
 * What must never be duplicated is a WRITE, and no write happens here: a
 * klantcontact is only ever created when the consuming app pushes for it,
 * against a `callId` it names. The idempotency that protects the record lives
 * there, not in this cache.
 *
 * Said out loud because "idempotent" in a task description reads as a promise,
 * and the next person to rely on it should know which half it is.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Kiss
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
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Kiss;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Remembers which call events have been processed, for long enough that a
 * PBX's retries land on the same answer.
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */
class CallEventDeduplicator {

	/**
	 * The cache namespace this keeps its marks in.
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'integriq-cti-events';

	/**
	 * How long a mark is kept, in seconds.
	 *
	 * Two hours: comfortably longer than any PBX retry window, and short
	 * enough that a `callId` a phone system reuses next week does not collide
	 * with one from today. Vendors DO reuse call ids; several reset them on a
	 * nightly restart.
	 *
	 * @var int
	 */
	public const TTL_SECONDS = 7200;

	/**
	 * The cache, or null on an instance with no distributed cache configured.
	 *
	 * @var ICache|null
	 */
	private ?ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory The Nextcloud cache factory.
	 *
	 * @return void
	 */
	public function __construct(ICacheFactory $cacheFactory) {
		if ($cacheFactory->isAvailable() === true) {
			$this->cache = $cacheFactory->createDistributed(prefix: self::CACHE_PREFIX);
			return;
		}

		$this->cache = null;

	}//end __construct()

	/**
	 * The key one event is remembered under.
	 *
	 * Scoped by SOURCE as well as call and kind. Two phone systems numbering
	 * their calls from one would otherwise silence each other's events, and
	 * the symptom would be "the second PBX we installed does not work".
	 *
	 * @param string $sourceId The CTI source.
	 * @param string $callId The PBX's call identifier.
	 * @param string $kind The call event kind.
	 *
	 * @return string The cache key.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function keyFor(string $sourceId, string $callId, string $kind): string {
		return sha1($sourceId.'|'.$callId.'|'.$kind);

	}//end keyFor()

	/**
	 * Claim this event, and say whether it was ours to claim.
	 *
	 * Returns true the FIRST time it is asked about an event and false after,
	 * so a caller processes on true and drops on false. One call rather than a
	 * check and a set, because the gap between those two is where a retry
	 * arriving in it gets processed twice.
	 *
	 * On an instance with no distributed cache this always returns true:
	 * without somewhere to remember, "have I seen this" has no answer, and
	 * answering false would drop every event on that instance rather than
	 * duplicating a few.
	 *
	 * @param string $sourceId The CTI source.
	 * @param string $callId The PBX's call identifier.
	 * @param string $kind The call event kind.
	 *
	 * @return boolean True when this caller should process the event.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function claim(string $sourceId, string $callId, string $kind): bool {
		if ($this->cache === null) {
			return true;
		}

		$key = $this->keyFor(sourceId: $sourceId, callId: $callId, kind: $kind);

		if ($this->cache->hasKey(key: $key) === true) {
			return false;
		}

		$this->cache->set(key: $key, value: 1, ttl: self::TTL_SECONDS);

		return true;

	}//end claim()

	/**
	 * Whether this instance can deduplicate at all.
	 *
	 * Exposed so a caller can say so in a log line rather than leaving an
	 * administrator to work out why a panel pops twice.
	 *
	 * @return boolean True when a distributed cache is available.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function isActive(): bool {
		return ($this->cache !== null);

	}//end isActive()

}//end class
