<?php

/**
 * Integriq Open Work Consumer contract.
 *
 * The contract an app implements to say what a leaver still holds. Integriq
 * asks; it never reads another app's data to find out. Reading dossiq's cases
 * from integriq would be one app reaching into another's store, which ADR-022
 * exists to prevent, and it would also make the answer wrong the first time the
 * other app changed its own model.
 *
 * An implementation that cannot answer MUST return null. It MUST NOT return
 * zero, because a confident zero about an app that never looked is exactly the
 * instrument that lies.
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
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Directory;

/**
 * An app that can say what an account still holds.
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-leavers-open-work-is-reported-never-silently-dropped-req-ds-004
 */
interface IOpenWorkConsumer {

	/**
	 * The app id this consumer answers for, as it is named in the run report.
	 *
	 * @return string The consumer id.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-leavers-open-work-is-reported-never-silently-dropped-req-ds-004
	 */
	public function getConsumerId(): string;

	/**
	 * How much open work the account still holds, or null when it cannot say.
	 *
	 * @param string $userId The Nextcloud account id.
	 *
	 * @return integer|null The count, or null when this consumer cannot answer.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-leavers-open-work-is-reported-never-silently-dropped-req-ds-004
	 */
	public function countOpenWork(string $userId): ?int;
}//end interface
