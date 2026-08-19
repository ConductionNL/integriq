<?php

/**
 * Raised when a Job or a Rule cannot be expressed as a flow.
 *
 * The same shape as {@see \OCA\OpenConnector\Exception\SynchronizationNotMigratableException},
 * deliberately: the task-3.3 generators REFUSE anything the flow vocabulary
 * cannot say, rather than emitting a flow that would do less than the entity it
 * replaces. Every refusal names the feature that is unsupported, so the reasons
 * travel as DATA beside the message — a caller (an occ command, a controller)
 * prints them one per line instead of parsing them back out of a translated
 * sentence.
 *
 * One class rather than one per entity type: the two generators refuse for
 * different reasons but in exactly the same shape, and `getSubject()` carries
 * the only thing that actually differs — which kind of entity was refused.
 *
 * @category Exception
 * @package  OCA\OpenConnector\Exception
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
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use RuntimeException;
use Throwable;

/**
 * An entity the flow vocabulary cannot yet express.
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */
class EntityNotMigratableException extends RuntimeException {

	/**
	 * The kind of entity that was refused: "job" or "rule".
	 *
	 * @var string
	 */
	private string $subject;

	/**
	 * One sentence per unsupported feature, in the order they were found.
	 *
	 * @var array<int, string>
	 */
	private array $reasons;

	/**
	 * Constructor.
	 *
	 * @param string $subject The kind of entity refused ("job" or "rule").
	 * @param string $message The human-readable summary.
	 * @param array $reasons One sentence per unsupported feature.
	 * @param Throwable|null $previous The underlying failure, when there was one.
	 */
	public function __construct(
		string $subject,
		string $message,
		array $reasons = [],
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, code: 0, previous: $previous);

		$this->subject = $subject;
		$this->reasons = $reasons;

	}//end __construct()

	/**
	 * The kind of entity that was refused.
	 *
	 * @return string Either "job" or "rule".
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function getSubject(): string {
		return $this->subject;

	}//end getSubject()

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
