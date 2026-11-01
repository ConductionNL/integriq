<?php

/**
 * Contract of the StufZdsConfirmationReceiver.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\StufZkn
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

namespace OCA\Integriq\Service\StufZkn;

/**
 * Public operations of {@see StufZdsConfirmationReceiver} that other components call.
 */
interface StufZdsConfirmationReceiverInterface {
	/**
	 * Receive one confirmation envelope.
	 *
	 * 200 with a Bv03 when it was recorded (or had been already), 413 when the
	 * body is over 2 MiB, 400 when the body is not a confirmation, 422 when the
	 * crossRefnummer is unknown or the credentials do not match, 503 when the
	 * source's password cannot be read.
	 *
	 * @param string $rawXml The raw request body.
	 *
	 * @return array The answer (ConfirmationAnswer).
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
	 */
	public function receive(string $rawXml): array;
}
