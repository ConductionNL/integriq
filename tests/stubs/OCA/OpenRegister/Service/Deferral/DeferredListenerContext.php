<?php

/**
 * Stub for OCA\OpenRegister\Service\Deferral\DeferredListenerContext.
 *
 * The BODIES are mirrored from the real class
 * (openregister/lib/Service/Deferral/DeferredListenerContext.php on
 * origin/development), not just the signatures. That matters here: this value
 * object is what turns the raw `oc_jobs.argument` payload back into entries,
 * and its tolerance for malformed input IS the behaviour a deferred job relies
 * on. A stub that returned a hand-built context would let a job test pass
 * against an argument shape the job list never produces.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deferral;

/**
 * Value object carrying the captured acting context of a deferred listener.
 */
final class DeferredListenerContext {

	/**
	 * Wrap a captured acting context.
	 *
	 * @param string|null                      $userId  Acting user id at dispatch time.
	 * @param string|null                      $orgUuid Active organisation uuid at dispatch time.
	 * @param array<int, array<string, mixed>> $entries Per-object entries for the job to process.
	 */
	public function __construct(
		private readonly ?string $userId,
		private readonly ?string $orgUuid,
		private readonly array $entries,
	) {
	}

	/**
	 * The acting user id captured at dispatch time.
	 *
	 * @return string|null
	 */
	public function getUserId(): ?string {
		return $this->userId;
	}

	/**
	 * The active organisation uuid captured at dispatch time.
	 *
	 * @return string|null
	 */
	public function getOrganisationUuid(): ?string {
		return $this->orgUuid;
	}

	/**
	 * The per-object entries the job must process.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getEntries(): array {
		return $this->entries;
	}

	/**
	 * Serialize to the array shape stored in `oc_jobs.argument`.
	 *
	 * @return array<string, mixed>
	 */
	public function toJobArguments(): array {
		return [
			'userId' => $this->userId,
			'organisationUuid' => $this->orgUuid,
			'entries' => array_values($this->entries),
		];
	}

	/**
	 * Rebuild a context from a job's argument payload.
	 *
	 * Tolerant of malformed input (non-array argument, missing keys, non-array
	 * entries): invalid pieces are dropped rather than thrown on, so a poisoned
	 * job row degrades to a logged no-op instead of a crash loop in cron.
	 *
	 * @param mixed $argument Raw job argument as delivered by the job list.
	 *
	 * @return self
	 */
	public static function fromJobArguments(mixed $argument): self {
		if (is_array($argument) === false) {
			return new self(userId: null, orgUuid: null, entries: []);
		}

		$userId = $argument['userId'] ?? null;
		if (is_string($userId) === false || $userId === '') {
			$userId = null;
		}

		$orgUuid = $argument['organisationUuid'] ?? null;
		if (is_string($orgUuid) === false || $orgUuid === '') {
			$orgUuid = null;
		}

		$entries = [];
		$rawList = $argument['entries'] ?? [];
		if (is_array($rawList) === true) {
			foreach ($rawList as $rawEntry) {
				if (is_array($rawEntry) === true) {
					$entries[] = $rawEntry;
				}
			}
		}

		return new self(userId: $userId, orgUuid: $orgUuid, entries: $entries);
	}
}
