<?php

/**
 * Integriq SendDecisionRun.
 *
 * The state of one batch decision in OptOutRegistry: the channel and the
 * canonical category every recipient is decided as, who asked, whether the
 * answer is only a probe, whether consent is required, the url unsubscribe
 * links are built on, and the rows of the chunk being decided.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Identity
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
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

use OCA\Integriq\Db\OptOut;

/**
 * One batch decision in progress. Built and used by OptOutRegistry only.
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
 */
final class SendDecisionRun {
	/**
	 * The asking app.
	 *
	 * @var string
	 */
	public string $sourceApp = '';

	/**
	 * The sender's correlation id.
	 *
	 * @var string
	 */
	public string $correlationId = '';

	/**
	 * True to answer only: no log row, no link material.
	 *
	 * @var bool
	 */
	public bool $probe = false;

	/**
	 * Whether consent is required.
	 *
	 * @var bool
	 */
	public bool $requiresConsent = false;

	/**
	 * The instance url the link is built on.
	 *
	 * @var string
	 */
	public string $baseUrl = '';

	/**
	 * The rows read for the chunk being decided.
	 *
	 * @var list<OptOut>
	 */
	public array $rows = [];

	/**
	 * Constructor.
	 *
	 * @param string $channel  The normalised channel.
	 * @param string $category The canonical category.
	 */
	public function __construct(
		public readonly string $channel,
		public readonly string $category,
	) {
	}//end __construct()

	/**
	 * The batch as a log row reads it.
	 *
	 * @return array{channel:string,category:string,sourceApp:string,correlationId:string,probe:bool}
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
	 */
	public function logContext(): array {
		return [
			'channel' => $this->channel,
			'category' => $this->category,
			'sourceApp' => $this->sourceApp,
			'correlationId' => $this->correlationId,
			'probe' => $this->probe,
		];

	}//end logContext()

	/**
	 * A decision about one recipient of the batch, in the batch's category.
	 *
	 * @param AddressedRecipient $addressed The recipient; an unusable address decides as empty.
	 *
	 * @return SendDecision The decision, without a code yet.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
	 */
	public function decisionFor(AddressedRecipient $addressed): SendDecision {
		return new SendDecision(address: (string)$addressed->key, category: $this->category);

	}//end decisionFor()
}//end class
