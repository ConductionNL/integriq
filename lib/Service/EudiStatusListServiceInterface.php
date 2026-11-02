<?php

/**
 * Contract of the EudiStatusListService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use RuntimeException;

/**
 * Public operations of {@see EudiStatusListService} that other components call.
 */
interface EudiStatusListServiceInterface {
	/**
	 * Assign the next free bit index for a new credential offer.
	 *
	 * @param string|null $organisationId The organisation uuid, or null for the default scope.
	 *
	 * @return array{statusListId: string, index: integer}
	 *
	 * @throws RuntimeException When the status list has exhausted its capacity.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-adapter-ships-as-a-catalogue-entry-backed-by-three-register-fragment-schemas-req-eudi-001
	 */
	public function assignIndex(?string $organisationId): array;

	/**
	 * Flip a credential's status-list bit to revoked.
	 *
	 * Idempotent — flipping an already-revoked bit is a no-op success, not
	 * an error (REQ-EUDI-009).
	 *
	 * @param string $statusListId The status list row uuid.
	 * @param integer $index The credential's assigned bit index.
	 *
	 * @return boolean True when the bit was actually flipped (false when it was already 1).
	 *
	 * @throws DoesNotExistException When the status list row does not exist.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-revocation-flips-one-status-list-bit-and-fires-a-signed-callback-req-eudi-009
	 */
	public function revokeIndex(string $statusListId, int $index): bool;

	/**
	 * Build and sign a fresh OAuth Status List Token for a status list row,
	 * caching the result on the row.
	 *
	 * @param ObjectEntity $entity The status list row.
	 *
	 * @return string The signed token.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-status-list-publishes-single-bit-revocation-only-req-eudi-008
	 */
	public function signAndCache(ObjectEntity $entity): string;

	/**
	 * Get the published token for a status list, signing a fresh one if
	 * none is cached yet.
	 *
	 * @param string $statusListId The status list row uuid.
	 *
	 * @return string|null The signed token, or null when the row does not exist.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-status-list-publishes-single-bit-revocation-only-req-eudi-008
	 */
	public function getPublishedToken(string $statusListId): ?string;

	/**
	 * Sweep every status list row and re-sign any token nearing its own
	 * expiry (REQ-EUDI-008b). Called by {@see \OCA\Integriq\BackgroundJob\EudiStatusListRefreshJob}.
	 *
	 * @return integer Number of tokens refreshed.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-status-list-refresh-keeps-the-published-token-ahead-of-its-own-expiry-req-eudi-008b
	 */
	public function refreshNearExpiry(): int;
}
