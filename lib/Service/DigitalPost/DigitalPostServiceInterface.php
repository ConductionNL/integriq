<?php

/**
 * Contract of the DigitalPostService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\DigitalPost
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

namespace OCA\Integriq\Service\DigitalPost;

use OCA\Integriq\Event\DigitalPostSendRequestedEvent;

/**
 * Public operations of {@see DigitalPostService} that other components call.
 */
interface DigitalPostServiceInterface {
	/**
	 * The register integriq's own objects live in.
	 */
	public const REGISTER = 'integriq';

	/**
	 * The schema a tracked letter is stored under.
	 */
	public const SCHEMA = 'digitalPostMessage';

	/**
	 * Handle a send request from another app.
	 *
	 * @param DigitalPostSendRequestedEvent $event The request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
	 */
	public function handleSendRequest(DigitalPostSendRequestedEvent $event): void;

	/**
	 * Ask every provider what became of the letters it took.
	 *
	 * @param array<int,array<string,mixed>> $messages The tracked messages to poll, keyed by `uuid`.
	 *
	 * @return int How many messages changed status.
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 */
	public function pollStatuses(array $messages): int;

	/**
	 * What a source's digital post looks like right now.
	 *
	 * @param array<int,array<string,mixed>> $messages The tracked messages for that source.
	 *
	 * @return array<string,mixed> Last send, last error and queue depth.
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 */
	public function health(array $messages): array;
}
