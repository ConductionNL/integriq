<?php

/**
 * Integriq RetryMessage: one outbound message being retried.
 *
 * Kept beside OutboundRetryService so asking the opt-out list and
 * re-dispatching take the message instead of its uuid, record and the
 * recipients one by one. Never leaves that class.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound
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
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-a-failed-send-is-retried-from-the-screen-and-the-retry-is-recorded-req-ocl-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound;

/**
 * The message a retry is about: its record, who retried it, and the recipients the opt-out list skipped.
 *
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-a-failed-send-is-retried-from-the-screen-and-the-retry-is-recorded-req-ocl-003
 */
final class RetryMessage {

	/**
	 * Who retried it.
	 *
	 * @var string
	 */
	public string $actorUid = '';

	/**
	 * The failed recipients the opt-out list skipped.
	 *
	 * @var array<int,string>
	 */
	public array $skipped = [];

	/**
	 * Constructor.
	 *
	 * @param string              $uuid   The record uuid.
	 * @param array<string,mixed> $record The record payload.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly array $record,
	) {

	}//end __construct()

	/**
	 * The category the first send was decided as.
	 *
	 * A record without the first decision (one written before opt-out
	 * checking, or a forward) is asked as `service`: never exempt.
	 *
	 * @return string The category.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
	 */
	public function optOutCategory(): string {
		return (string)($this->optOutContext()['category'] ?? 'service');
	}//end optOutCategory()

	/**
	 * The options the opt-out list is asked with.
	 *
	 * @return array{caseRef:string,sourceApp:string,correlationId:string} The options.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
	 */
	public function optOutOptions(): array {
		return [
			'caseRef' => (string)($this->optOutContext()['caseRef'] ?? ''),
			'sourceApp' => (string)($this->record['sourceApp'] ?? 'integriq'),
			'correlationId' => (string)($this->record['correlationId'] ?? $this->uuid),
		];
	}//end optOutOptions()

	/**
	 * The first send's opt-out decision, empty when the record has none.
	 *
	 * @return array<string,mixed> The decision context.
	 */
	private function optOutContext(): array {
		$context = ($this->record['context']['optOut'] ?? []);
		if (is_array($context) === false) {
			return [];
		}

		return $context;
	}//end optOutContext()
}//end class
