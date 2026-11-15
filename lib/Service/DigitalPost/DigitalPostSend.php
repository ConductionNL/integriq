<?php

/**
 * One digital post send in progress.
 *
 * Kept beside DigitalPostService so storing, sending and recording a letter
 * take the send instead of the request, the provider, the source
 * configuration and the opt-out decision one by one. Never leaves that class.
 *
 * @category Service
 * @package  OCA\Integriq\Service\DigitalPost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-a-send-is-a-typed-command-with-a-tracked-message-req-dpa-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\DigitalPost;

use OCA\Integriq\Event\DigitalPostSendRequestedEvent;

/**
 * A letter from its request to the provider's answer: the request, the source,
 * the opt-out decision, the stored message and its outbound log row.
 *
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-a-send-is-a-typed-command-with-a-tracked-message-req-dpa-002
 */
final class DigitalPostSend {

	/**
	 * The provider the source names.
	 *
	 * @var string
	 */
	public readonly string $providerId;

	/**
	 * The opt-out list's allowing decision.
	 *
	 * @var array<string,mixed>
	 */
	public array $decision = [];

	/**
	 * The body as the opt-out gate composed it.
	 *
	 * @var string
	 */
	public string $body = '';

	/**
	 * The message as stored before the provider is called.
	 *
	 * @var array<string,mixed>
	 */
	public array $message = [];

	/**
	 * The stored message's id.
	 *
	 * @var string
	 */
	public string $messageId = '';

	/**
	 * The outbound log row, null when none was opened.
	 *
	 * @var string|null
	 */
	public ?string $logRow = null;

	/**
	 * Constructor.
	 *
	 * @param DigitalPostSendRequestedEvent $event  The request.
	 * @param array<string,mixed>           $config The source configuration.
	 */
	public function __construct(
		public readonly DigitalPostSendRequestedEvent $event,
		public readonly array $config,
	) {
		$this->providerId = (string)($config['providerId'] ?? '');

	}//end __construct()
}//end class
