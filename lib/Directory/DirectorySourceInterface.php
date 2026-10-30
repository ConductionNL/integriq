<?php

/**
 * Contract of the DirectorySource.
 *
 * @category Contract
 * @package  OCA\Integriq\Directory
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

namespace OCA\Integriq\Directory;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see DirectorySource} that other components call.
 */
interface DirectorySourceInterface {
	/**
	 * Every enabled directory connection, as Source objects.
	 *
	 * Read under RBAC, so a caller who may not see a Source is answered as if it
	 * did not exist. `source` is admin-only (register.d/99-source-lockdown.json)
	 * because it holds credentials.
	 *
	 * @return array<int,ObjectEntity> The directory connections.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	public function findConnections(): array;

	/**
	 * The same connections, read outside RBAC, for consulting them as policy.
	 *
	 * Separate from findConnections() rather than a flag on it, because the two
	 * differ in who is asking and not merely in how. Here the reader is the app
	 * deciding what it is permitted to do, and the connection is never rendered
	 * to the caller.
	 *
	 * The distinction matters because `source` is admin-only: an RBAC read on
	 * behalf of a SCIM consumer answers nothing, and "no connections" would then
	 * be indistinguishable from "this instance manages no groups". One means the
	 * reader may not look; the other means there is nothing to see. Confusing
	 * them would refuse every write on a correctly configured instance.
	 *
	 * @return array<int,ObjectEntity> The directory connections.
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-writes-only-the-groups-a-connection-declares-it-manages-req-ds-009
	 */
	public function findConnectionsForPolicy(): array;

	/**
	 * Read one directory connection.
	 *
	 * @param ObjectEntity $connection The directory connection Source.
	 *
	 * @return DirectorySnapshot What the directory answered.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	public function read(ObjectEntity $connection): DirectorySnapshot;
}
