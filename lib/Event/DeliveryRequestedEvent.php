<?php

/**
 * Integriq DeliveryRequested Event.
 *
 * The ADR-041 cross-app command contract for outbound deliveries: a sibling
 * Conduction app (dossiq, decidiq, ...) composes WHAT must be delivered and
 * dispatches this typed event; Integriq owns HOW it travels (transport,
 * retry, circuit breaking, dead-lettering) by fanning the request out through
 * its CloudEvents pipeline. The consumer MUST guard the dispatch with
 * class_exists() and treat an unhandled event as a refused delivery — never
 * as a delivered one.
 *
 * @category Event
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/absorb-dossiq-deliveries/specs/delivery-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * Typed cross-app command: "deliver this payload on my behalf".
 *
 * Carries provenance (which app, which subject object), a delivery payload
 * reference, and a synchronous result slot the in-process listener writes:
 * `isHandled()` + `getResultId()` (the persisted CloudEvent uuid) +
 * `getMatchedSubscriptions()` (how many delivery routes picked it up — zero
 * means the request was accepted but nothing is configured to deliver it,
 * which a fail-closed consumer records as a refusal, not a success).
 *
 * @spec openspec/changes/absorb-dossiq-deliveries/specs/delivery-intake/spec.md
 */
class DeliveryRequestedEvent extends Event {
	/**
	 * Whether an Integriq listener handled the request.
	 */
	private bool $handled = false;

	/**
	 * Uuid of the persisted CloudEvent `event` object, once handled.
	 */
	private ?string $resultId = null;

	/**
	 * How many active event subscriptions matched the delivery request.
	 */
	private int $matchedSubscriptions = 0;

	/**
	 * Constructor.
	 *
	 * @param string $sourceApp The requesting app id (e.g. `dossiq`).
	 * @param string $subjectRegister The OpenRegister register slug/id of the subject object.
	 * @param string $subjectSchema The schema slug/id of the subject object.
	 * @param string $subjectId The subject object id/uuid (e.g. the case id).
	 * @param string $subjectLabel Human-readable label for the subject.
	 * @param string $deliveryKind What is being delivered (e.g. `besluit-publication`).
	 * @param string $channel The requested delivery channel (e.g. `gemeenteblad`).
	 * @param array<string, mixed> $payload The delivery payload reference (composed by the source app).
	 * @param string $correlationId Caller-generated id echoed on the concluded event.
	 * @param string|null $externalReference Optional external reference (e.g. besluit identificatie).
	 * @param string|null $userId The acting Nextcloud user, or null for system-produced requests.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $subjectRegister,
		private readonly string $subjectSchema,
		private readonly string $subjectId,
		private readonly string $subjectLabel,
		private readonly string $deliveryKind,
		private readonly string $channel,
		private readonly array $payload,
		private readonly string $correlationId,
		private readonly ?string $externalReference = null,
		private readonly ?string $userId = null,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The requesting app id.
	 *
	 * @return string The source app id.
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The subject object's register.
	 *
	 * @return string The register slug/id.
	 */
	public function getSubjectRegister(): string {
		return $this->subjectRegister;
	}//end getSubjectRegister()

	/**
	 * The subject object's schema.
	 *
	 * @return string The schema slug/id.
	 */
	public function getSubjectSchema(): string {
		return $this->subjectSchema;
	}//end getSubjectSchema()

	/**
	 * The subject object id.
	 *
	 * @return string The object id/uuid.
	 */
	public function getSubjectId(): string {
		return $this->subjectId;
	}//end getSubjectId()

	/**
	 * Human-readable subject label.
	 *
	 * @return string The label.
	 */
	public function getSubjectLabel(): string {
		return $this->subjectLabel;
	}//end getSubjectLabel()

	/**
	 * What is being delivered.
	 *
	 * @return string The delivery kind.
	 */
	public function getDeliveryKind(): string {
		return $this->deliveryKind;
	}//end getDeliveryKind()

	/**
	 * The requested delivery channel.
	 *
	 * @return string The channel.
	 */
	public function getChannel(): string {
		return $this->channel;
	}//end getChannel()

	/**
	 * The delivery payload reference.
	 *
	 * @return array<string, mixed> The payload.
	 */
	public function getPayload(): array {
		return $this->payload;
	}//end getPayload()

	/**
	 * The caller's correlation id.
	 *
	 * @return string The correlation id.
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}//end getCorrelationId()

	/**
	 * Optional external reference.
	 *
	 * @return string|null The external reference.
	 */
	public function getExternalReference(): ?string {
		return $this->externalReference;
	}//end getExternalReference()

	/**
	 * The acting Nextcloud user.
	 *
	 * @return string|null The user id, or null for system-produced requests.
	 */
	public function getUserId(): ?string {
		return $this->userId;
	}//end getUserId()

	/**
	 * Mark the request as handled by an Integriq listener.
	 *
	 * @param bool $handled Whether the request was handled.
	 *
	 * @return void
	 */
	public function setHandled(bool $handled): void {
		$this->handled = $handled;
	}//end setHandled()

	/**
	 * Whether an Integriq listener handled the request.
	 *
	 * @return bool True when handled.
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * Record the persisted CloudEvent uuid.
	 *
	 * @param string $resultId The event object uuid.
	 *
	 * @return void
	 */
	public function setResultId(string $resultId): void {
		$this->resultId = $resultId;
	}//end setResultId()

	/**
	 * The persisted CloudEvent uuid, once handled.
	 *
	 * @return string|null The event object uuid.
	 */
	public function getResultId(): ?string {
		return $this->resultId;
	}//end getResultId()

	/**
	 * Record how many subscriptions matched.
	 *
	 * @param int $matchedSubscriptions The matched subscription count.
	 *
	 * @return void
	 */
	public function setMatchedSubscriptions(int $matchedSubscriptions): void {
		$this->matchedSubscriptions = $matchedSubscriptions;
	}//end setMatchedSubscriptions()

	/**
	 * How many active subscriptions matched the delivery request.
	 *
	 * @return int The matched subscription count.
	 */
	public function getMatchedSubscriptions(): int {
		return $this->matchedSubscriptions;
	}//end getMatchedSubscriptions()
}//end class
