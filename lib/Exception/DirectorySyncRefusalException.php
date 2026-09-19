<?php

/**
 * Integriq Directory Sync Refusal Exception.
 *
 * Raised when a directory run stops on purpose rather than on an error: a
 * mapping naming a Nextcloud group that does not exist while creation is off,
 * or a run whose removals exceed the configured deletion ratio. Both refusals
 * exist because the third behaviour, dropping the membership quietly, is the
 * one that produces a permission nobody can explain.
 *
 * The message MUST name what was refused — the group, or the ratio and the
 * threshold it crossed — because a refusal an administrator cannot act on is
 * indistinguishable from a failure.
 *
 * @category Exception
 * @package  OCA\Integriq\Exception
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

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Signals that a directory run stopped before writing, and says what it hit.
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-the-directory-to-group-mapping-is-declared-not-coded-req-ds-002
 */
class DirectorySyncRefusalException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param string $message The refusal, naming what it refused.
	 * @param array<string,mixed> $context The machine-readable detail for the run record.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-the-directory-to-group-mapping-is-declared-not-coded-req-ds-002
	 */
	public function __construct(
		string $message,
		private readonly array $context = [],
	) {
		parent::__construct(message: $message);

	}//end __construct()

	/**
	 * The machine-readable detail for the run record.
	 *
	 * @return array<string,mixed> The context.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-every-run-says-what-it-changed-req-ds-006
	 */
	public function getContext(): array {
		return $this->context;

	}//end getContext()
}//end class
