<?php

/**
 * A delivery request that may not be sent, carrying every reason.
 *
 * Every refusal rather than the first, because a handler who corrects one and
 * meets the next on the retry learns the rules one round trip at a time.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Exception
 * @package  OCA\Integriq\Service\Recipients
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://Integriq.app
 *
 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Recipients;

use RuntimeException;

/**
 * Thrown when a recipient decision would not be a lawful send.
 */
class RecipientRefusedException extends RuntimeException {

	/**
	 * Build the refusal.
	 *
	 * @param string   $message  Every reason, joined.
	 * @param string[] $refusals Every reason, separately, for a surface to list.
	 */
	public function __construct(string $message, private readonly array $refusals = []) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * Every reason this request was refused.
	 *
	 * @return string[] The refusals.
	 */
	public function getRefusals(): array {
		return $this->refusals;
	}//end getRefusals()
}//end class
