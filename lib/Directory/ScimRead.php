<?php

/**
 * Integriq Directory SCIM Read: one paged read of a SCIM `/Users` endpoint —
 * the connection and endpoint, the page size, where the next page starts and
 * the rows read so far.
 *
 * @category Directory
 * @package  OCA\Integriq\Directory
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Directory;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One SCIM read in progress. Built and used by DirectorySource only.
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
 */
final class ScimRead {
	/**
	 * The SCIM page size.
	 *
	 * @var integer
	 */
	public int $pageSize = 200;

	/**
	 * The 1-based SCIM start index of the next page.
	 *
	 * @var integer
	 */
	public int $startIndex = 1;

	/**
	 * The rows read so far.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $rows = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $connection The directory connection Source.
	 * @param string       $endpoint   The SCIM users endpoint.
	 */
	public function __construct(
		public readonly ObjectEntity $connection,
		public readonly string $endpoint,
	) {
	}//end __construct()
}//end class
