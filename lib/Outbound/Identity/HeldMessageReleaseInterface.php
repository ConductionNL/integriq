<?php

/**
 * Contract of the HeldMessageRelease.
 *
 * @category Contract
 * @package  OCA\Integriq\Outbound\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

use RuntimeException;

/**
 * Public operations of {@see HeldMessageRelease} that other components call.
 */
interface HeldMessageReleaseInterface {
	/**
	 * Release a message held for review and send it through the delivery pipeline.
	 *
	 * @param string $uuid     The message record uuid.
	 * @param string $actorUid Who released it.
	 *
	 * @return array{message:string,status:string,retry:array<string,mixed>} The release and the send's outcome.
	 *
	 * @throws RuntimeException When the message is not held for review.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on HeldMessageRelease::release()
	 */
	public function release(string $uuid, string $actorUid): array;
}
