<?php

/**
 * Integriq recipient resolution.
 *
 * One resolution of a message's recipient list once its refusals are
 * cleared: who resolves it and when (stamped on every addition and
 * suppression that names no user of its own), and the suppressions by
 * the address they keep off the message.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Recipients
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://Integriq.app
 *
 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Recipients;

use DateTimeImmutable;

/**
 * Who resolves a recipient list, when, and what is kept off it.
 *
 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md
 */
final class RecipientResolution {

	/**
	 * Constructor.
	 *
	 * @param string                              $user         Who is resolving.
	 * @param DateTimeImmutable                   $moment       When.
	 * @param array<string, array<string, mixed>> $suppressions The suppressions by trimmed address.
	 */
	public function __construct(
		public readonly string $user,
		public readonly DateTimeImmutable $moment,
		public readonly array $suppressions,
	) {

	}//end __construct()

	/**
	 * The moment as the record writes it.
	 *
	 * @return string The ATOM timestamp.
	 *
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md
	 */
	public function at(): string {
		return $this->moment->format(DATE_ATOM);

	}//end at()
}//end class
