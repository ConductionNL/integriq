<?php

/**
 * Raised when a Synchronization cannot be expressed as a decomposed flow.
 *
 * The migration generator REFUSES rather than emitting a flow that would do
 * less than the synchronization it replaces. Every refusal names the feature
 * that is unsupported, so the reasons are carried as data alongside the
 * message: a caller (the occ command, a controller) prints them one per line
 * instead of parsing them back out of a translated sentence.
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
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use RuntimeException;
use Throwable;

/**
 * A synchronization the decomposed flow cannot yet express.
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */
class SynchronizationNotMigratableException extends RuntimeException {

	/**
	 * One sentence per unsupported feature, in the order they were found.
	 *
	 * @var array<int, string>
	 */
	private array $reasons;

	/**
	 * Constructor.
	 *
	 * @param string $message The human-readable summary.
	 * @param array $reasons One sentence per unsupported feature.
	 * @param Throwable|null $previous The underlying failure, when there was one.
	 */
	public function __construct(string $message, array $reasons = [], ?Throwable $previous = null) {
		parent::__construct(message: $message, code: 0, previous: $previous);

		$this->reasons = $reasons;

	}//end __construct()

	/**
	 * The unsupported features, one sentence each.
	 *
	 * @return array<int, string> The refusal reasons.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function getReasons(): array {
		return $this->reasons;
	}//end getReasons()
}//end class
