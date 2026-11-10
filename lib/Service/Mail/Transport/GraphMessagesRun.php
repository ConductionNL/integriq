<?php

/**
 * Integriq Graph messages run.
 *
 * What one Graph mailbox read carries from the folder to each message: the
 * folder's messages collection url, which the attachment reads hang off,
 * and the bearer token.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Mail\Transport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/mail-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Mail\Transport;

/**
 * One Graph mailbox read: the messages collection and the token.
 *
 * @spec openspec/specs/mail-intake/spec.md
 */
final class GraphMessagesRun {

	/**
	 * Constructor.
	 *
	 * @param string $messagesUrl The folder's messages collection url.
	 * @param string $token       The bearer token.
	 */
	public function __construct(
		public readonly string $messagesUrl,
		public readonly string $token,
	) {

	}//end __construct()
}//end class
