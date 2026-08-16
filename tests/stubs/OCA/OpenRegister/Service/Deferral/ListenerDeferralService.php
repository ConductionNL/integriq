<?php

/**
 * Stub for OCA\OpenRegister\Service\Deferral\ListenerDeferralService.
 *
 * The method surface is a byte-for-byte mirror of the real class
 * (openregister/lib/Service/Deferral/ListenerDeferralService.php) — parameter
 * names, order, types and defaults included. A stub that drifts from the class
 * it stands in for produces a test that passes against a signature nobody
 * ships; the fleet has already paid for that once.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deferral;

/**
 * Minimal stub for OCA\OpenRegister\Service\Deferral\ListenerDeferralService.
 */
class ListenerDeferralService {

	public const MODE_INLINE = 'inline';

	public const MODE_BACKGROUND = 'background';

	public const DEFAULT_CHUNK_SIZE = 100;

	public function isDeferralEnabled(): bool {
		return true;
	}

	public function defer(
		string $jobClass,
		array $entry,
		int $chunkSize = self::DEFAULT_CHUNK_SIZE,
		?string $dedupeKey = null,
	): void {
	}

	public function flushAll(): void {
	}
}
