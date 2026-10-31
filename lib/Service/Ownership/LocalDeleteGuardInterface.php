<?php

/**
 * Contract of the LocalDeleteGuard.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Ownership
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Ownership;

use InvalidArgumentException;

/**
 * Public operations of {@see LocalDeleteGuard} that other components call.
 */
interface LocalDeleteGuardInterface {
	/**
	 * The key an override is recorded on the object under.
	 */
	public const OVERRIDE_KEY = 'ownershipDeleteOverride';

	/**
	 * Decide whether a delete may go ahead.
	 *
	 * @param OwnershipState $ownership The record's ownership.
	 * @param string|null $reason The typed override reason, or null for no override.
	 * @param string|null $userId The user overriding.
	 *
	 * @return array<string,mixed>|null The override record to store, or null when no override was needed.
	 *
	 * @throws InvalidArgumentException When the delete is refused.
	 *
	 * @spec openspec/specs/source-owned-records/spec.md
	 */
	public function guard(OwnershipState $ownership, ?string $reason = null, ?string $userId = null): ?array;

	/**
	 * The refusal a person reads, naming the synchronisation.
	 *
	 * @param OwnershipState $ownership The record's ownership.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/specs/source-owned-records/spec.md
	 */
	public function refusalMessage(OwnershipState $ownership): string;
}
