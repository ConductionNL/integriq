<?php

/**
 * Contract of the IntakeDocumentDispatcher.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Mail
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

namespace OCA\Integriq\Service\Mail;

/**
 * Public operations of {@see IntakeDocumentDispatcher} that other components call.
 */
interface IntakeDocumentDispatcherInterface {
	/**
	 * Whether the intake inbox is reachable at all.
	 *
	 * @return bool True when the event class is present on this instance.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on IntakeDocumentDispatcher::isAvailable()
	 */
	public function isAvailable(): bool;

	/**
	 * Offer one attachment to the intake inbox.
	 *
	 * @param array<string,mixed> $payload The intake payload: channel, name, mime, size, content,
	 *                                     sender, subject, sourceRef, receivedAt.
	 *
	 * @return bool True when the event was dispatched, false when nothing here can receive it.
	 *
	 * @spec openspec/specs/mail-intake/spec.md
	 */
	public function dispatch(array $payload): bool;
}
